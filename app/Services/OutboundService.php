<?php

namespace App\Services;

use App\Models\InvoiceEvent;
use App\Models\OutboundDocument;
use App\Models\SyncCursor;
use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\InvoiceNinja\InvoiceNinjaException;
use App\Services\InvoiceNinja\Statics;
use App\Services\Mapping\EnInvoiceMapper;
use App\Services\Mapping\MappingException;
use App\Services\SuperPdp\SuperPdpClient;
use App\Services\SuperPdp\SuperPdpException;
use App\Support\Archive;
use App\Support\Journal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Invoice Ninja → SUPER PDP.
 *
 *  discover(): finds sent invoices / credits in Invoice Ninja and records them
 *  prepare():  maps to EN16931, checks the directory, builds the Factur-X
 *              (SUPER PDP converter) and validates it (SUPER PDP schematrons)
 *  send():     uploads once, never twice (lock + external_id lookup)
 *  applyEvent(): lifecycle statuses → document state → Invoice Ninja fields
 */
class OutboundService
{
    public const ENTITY_ENDPOINTS = ['invoice' => 'invoices', 'credit' => 'credits'];

    private const SENT_STATUSES = ['invoice' => [2, 3, 4], 'credit' => [2, 3, 4]];

    public function __construct(
        private readonly InvoiceNinjaClient $invoiceNinja,
        private readonly SuperPdpClient $superPdp,
        private readonly EnInvoiceMapper $mapper,
        private readonly SellerResolver $seller,
        private readonly Statics $statics,
        private readonly PdfCoherence $pdfCoherence,
    ) {}

    // --- Discovery ----------------------------------------------------------------

    /** @return int number of documents created or updated */
    public function discover(bool $full = false): int
    {
        $since = $full ? null : (int) SyncCursor::get('invoiceninja.outbound.updated_at', 0);
        $started = now()->subMinutes(2)->getTimestamp();
        $count = 0;

        foreach (self::ENTITY_ENDPOINTS as $kind => $endpoint) {
            foreach ($this->invoiceNinja->listUpdated($endpoint, $since ?: null) as $doc) {
                if ($this->upsert($kind, $doc)) {
                    $count++;
                }
            }
        }

        SyncCursor::put('invoiceninja.outbound.updated_at', $started);

        return $count;
    }

    /** Records (or refreshes) one Invoice Ninja document. Returns null when out of scope. */
    public function upsert(string $kind, array $doc, bool $track = false): ?OutboundDocument
    {
        $existing = OutboundDocument::query()->where('entity_type', $kind)->where('in_id', $doc['id'])->first();
        $fields = config('einvoicing.invoice_ninja.fields');
        $action = self::actionOf((string) ($doc[$fields['action']] ?? ''));
        $retry = $action === 'retry';
        $requested = in_array($action, ['send', 'send_deposit', 'retry'], true);
        $skip = $action === 'skip';
        $sent = in_array((int) ($doc['status_id'] ?? 0), self::SENT_STATUSES[$kind], true) && ! ($doc['is_deleted'] ?? false);
        $recent = blank(config('einvoicing.send_from_date')) || (($doc['date'] ?? '') >= config('einvoicing.send_from_date'));

        if (! $existing && ! $track && (! $sent || (! $recent && ! $requested))) {
            return null;
        }

        $document = $existing ?? new OutboundDocument([
            'entity_type' => $kind,
            'in_id' => $doc['id'],
            'external_id' => OutboundDocument::externalIdFor($kind, $doc['id']),
            'state' => OutboundDocument::DISCOVERED,
        ]);

        $document->fill([
            'number' => $doc['number'] ?? null,
            'issue_date' => filled($doc['date'] ?? null) ? $doc['date'] : null,
            'client_name' => $doc['client']['display_name'] ?? $doc['client']['name'] ?? $document->client_name,
            'total' => $doc['amount'] ?? null,
            'in_status_id' => (int) ($doc['status_id'] ?? 0),
            'requested' => $requested,
            'deposit' => $retry ? (bool) $document->deposit : $action === 'send_deposit',
        ]);

        if (! $document->isFinal()) {
            $wanted = $sent && ($requested || config('einvoicing.auto_send'));

            $document->state = match (true) {
                $skip => OutboundDocument::SKIPPED,
                ! $sent => OutboundDocument::DISCOVERED,
                $wanted && in_array($document->state, [OutboundDocument::DISCOVERED, OutboundDocument::SKIPPED], true) => OutboundDocument::PENDING,
                ! $wanted && in_array($document->state, [OutboundDocument::PENDING, OutboundDocument::SKIPPED], true) => OutboundDocument::DISCOVERED,
                default => $document->state,
            };

            // The document changed in Invoice Ninja after it was prepared: prepare again.
            if ($document->exists && $document->isDirty(['number', 'issue_date', 'total']) && in_array($document->state, [OutboundDocument::READY, OutboundDocument::INVALID], true)) {
                $document->state = $wanted ? OutboundDocument::PENDING : OutboundDocument::DISCOVERED;
            }

            if ($retry && $sent && in_array($document->state, [OutboundDocument::INVALID, OutboundDocument::ERROR, OutboundDocument::READY], true)) {
                $document->state = OutboundDocument::PENDING;
                $document->next_attempt_at = null;
            }
        }

        $isNew = ! $document->exists;
        $document->save();

        if ($isNew) {
            Journal::info('outbound', 'discovered', "Document {$document->number} détecté dans Invoice Ninja.", $document);
        }

        if ($retry) {
            $this->acknowledgeRetry($document, $fields['action']);
        }

        return $document;
    }

    /** @return 'send'|'send_deposit'|'skip'|'retry'|null */
    public static function actionOf(string $value): ?string
    {
        $value = trim($value);

        foreach (['send', 'send_deposit', 'skip', 'retry'] as $action) {
            if ($value !== '' && $value === config("einvoicing.invoice_ninja.action_{$action}")) {
                return $action;
            }
        }

        return config('einvoicing.invoice_ninja.action_aliases')[$value] ?? null;
    }

    /** "Relancer" is a one-shot request: the field goes back to "À transmettre" so it is not repeated. */
    private function acknowledgeRetry(OutboundDocument $document, string $field): void
    {
        $action = config($document->deposit ? 'einvoicing.invoice_ninja.action_send_deposit' : 'einvoicing.invoice_ninja.action_send');

        try {
            $this->invoiceNinja->update(self::ENTITY_ENDPOINTS[$document->entity_type], $document->in_id, [$field => $action]);
            Journal::info('outbound', 'retry_requested', $document->isFinal()
                ? "Relance ignorée pour {$document->number} : déjà déposée sur SUPER PDP."
                : "Relance demandée depuis Invoice Ninja pour {$document->number} : nouvelle préparation.", $document);
        } catch (Throwable $e) {
            Journal::warning('outbound', 'retry_ack_failed', "Relance de {$document->number} : champ « Facture électronique » non remis à « {$action} » ({$e->getMessage()}).", $document);
        }
    }

    // --- Processing ---------------------------------------------------------------

    /** Prepares pending documents and sends the ready ones that may be sent. */
    public function processQueue(): array
    {
        $stats = ['prepared' => 0, 'sent' => 0, 'invalid' => 0, 'errors' => 0];

        $due = OutboundDocument::query()
            ->whereIn('state', [OutboundDocument::PENDING, OutboundDocument::ERROR, OutboundDocument::READY])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->get();

        foreach ($due as $document) {
            foreach ($this->processOne($document) as $outcome) {
                $stats[$outcome]++;
            }
        }

        return $stats;
    }

    /**
     * Prepares, then sends when allowed, one document. Used by the queue and
     * right after an Invoice Ninja webhook, so that the status shows up in
     * Invoice Ninja within seconds of the request.
     *
     * @return list<'prepared'|'sent'|'invalid'|'errors'>
     */
    public function processOne(OutboundDocument $document): array
    {
        $lock = Cache::lock("outbound-process-{$document->id}", 600);

        if (! $lock->get()) {
            return [];
        }

        $outcomes = [];

        try {
            $document->refresh();

            if (in_array($document->state, [OutboundDocument::PENDING, OutboundDocument::ERROR], true) && $document->superpdp_id === null) {
                $this->prepare($document);
                $document->refresh();
                $outcomes[] = $document->state === OutboundDocument::READY ? 'prepared' : ($document->state === OutboundDocument::INVALID ? 'invalid' : 'errors');
            }

            if ($document->state === OutboundDocument::READY && $this->maySend($document)) {
                $this->send($document);
                $document->refresh();
                $outcomes[] = $document->superpdp_id ? 'sent' : 'errors';
            }
        } finally {
            $lock->release();
        }

        return $outcomes;
    }

    public static function emissionEnabled(): bool
    {
        return config('einvoicing.enabled') && config('einvoicing.send_enabled');
    }

    public function maySend(OutboundDocument $document): bool
    {
        return self::emissionEnabled()
            && $this->superPdp->configured()
            && ($document->requested || config('einvoicing.auto_send'));
    }

    /**
     * Maps the Invoice Ninja document to EN16931 and runs every local check
     * (identity, consistency with Invoice Ninja). Sends nothing anywhere; only
     * the public directory is queried when $directory is true.
     */
    private function build(OutboundDocument $document, bool $directory = true): array
    {
        $endpoint = self::ENTITY_ENDPOINTS[$document->entity_type];
        $doc = $document->entity_type === 'credit' ? $this->invoiceNinja->credit($document->in_id) : $this->invoiceNinja->invoice($document->in_id);
        $seller = $this->seller->resolve();
        $sandbox = $this->superPdp->environment() === 'sandbox';
        $configuredSiren = substr(preg_replace('/\D/', '', (string) ($seller['siren'] ?? '')), 0, 9);

        if (! $sandbox && filled($seller['account_siren'] ?? null) && $configuredSiren !== $seller['account_siren']) {
            throw new MappingException("Le SIREN vendeur ({$configuredSiren}) ne correspond pas au compte SUPER PDP ({$seller['account_siren']}).");
        }

        $preceding = $this->precedingFor($document, $doc);

        if ($preceding && blank($doc['invoice_id'] ?? null)) {
            // The PO field carried the original invoice number, not a purchase order (BT-13).
            $doc['po_number'] = '';
        }

        $mapped = $this->mapper->map($doc, $seller, fn ($id) => $this->statics->country($id), [
            'kind' => $document->entity_type,
            'deposit' => $document->deposit,
            'preceding' => $preceding,
            'currency_code' => $this->statics->currency($doc['client']['settings']['currency_id'] ?? null),
            'payment_terms' => config('einvoicing.payment_terms'),
            'sandbox_buyer_address' => $sandbox ? (string) config('einvoicing.superpdp.sandbox_buyer_address', '') : null,
        ]);

        $coherence = $this->coherence($doc, $mapped, $seller['pdf'] ?? []);
        $warnings = array_merge($mapped['warnings'], $coherence['warnings']);
        $directoryStatus = $directory ? $this->checkDirectory($mapped, $sandbox, $warnings) : null;

        return compact('endpoint', 'doc', 'seller', 'sandbox', 'mapped', 'coherence', 'warnings') + ['directory' => $directoryStatus];
    }

    /**
     * Local preview: EN16931 data, Invoice Ninja PDF, consistency checks and a
     * readable HTML rendering, archived under preview/. Nothing is converted,
     * validated or deposited at SUPER PDP; with $directory the public directory
     * is looked up for the customer's SIREN.
     */
    public function preview(OutboundDocument $document, bool $directory = true): array
    {
        $built = $this->build($document, $directory);
        $mapped = $built['mapped'];
        $pdf = $this->invoiceNinja->pdf($built['endpoint'], $document->in_id);
        $pdfCheck = $this->pdfCoherence->check($pdf, $mapped['invoice']);
        $errors = array_merge($built['coherence']['errors'], $pdfCheck['errors']);
        $warnings = array_merge($built['warnings'], $pdfCheck['warnings']);

        $folder = 'preview/'.now()->format('Ymd-His').'-'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $document->number);
        $files = [
            'en16931' => Archive::put($folder, "{$document->number}.json", json_encode($mapped['invoice'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json'),
            'pdf' => Archive::put($folder, "{$document->number}.pdf", $pdf, 'application/pdf'),
        ];
        $report = [
            'number' => $document->number,
            'generated_at' => now()->toIso8601String(),
            'environment' => $this->superPdp->environment(),
            'processing_rule' => $mapped['processing_rule'],
            'type_code' => $mapped['type_code'],
            'directory' => $built['directory'],
            'errors' => $errors,
            'warnings' => $warnings,
            'pdf_checks' => $pdfCheck['checks'],
            'transmitted' => false,
        ];
        $files['html'] = Archive::put($folder, "{$document->number}-apercu.html", view('preview', ['en' => $mapped['invoice'], 'report' => $report])->render(), 'text/html');
        $files['report'] = Archive::put($folder, "{$document->number}-controles.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'application/json');

        return $report + ['ok' => $errors === [], 'files' => $files, 'mapped' => $mapped];
    }

    /**
     * Builds and validates the Factur-X. Never talks to the recipient: only the
     * public directory, converter and validator are used.
     */
    public function prepare(OutboundDocument $document, bool $dryRun = false): array
    {
        $result = ['ok' => false, 'errors' => [], 'warnings' => []];

        try {
            ['endpoint' => $endpoint, 'mapped' => $mapped, 'coherence' => $coherence, 'warnings' => $warnings, 'directory' => $directory] = $this->build($document);

            $folder = ($dryRun ? 'dry-run/'.now()->format('Ymd-His') : "outbound/{$document->id}").'-'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $document->number);
            $json = json_encode($mapped['invoice'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $files = ['en16931' => Archive::put($folder, "{$document->number}.json", $json, 'application/json')];

            $pdf = $this->invoiceNinja->pdf($endpoint, $document->in_id);
            $pdfCheck = $this->pdfCoherence->check($pdf, $mapped['invoice']);
            $warnings = array_merge($warnings, $pdfCheck['warnings']);
            $coherence['errors'] = array_merge($coherence['errors'], $pdfCheck['errors']);
            $facturx = $this->superPdp->convertToFacturX($mapped['invoice'], $pdf, "{$document->number}.pdf");
            $files['facturx'] = Archive::put($folder, "{$document->number}-facturx.pdf", $facturx, 'application/pdf');

            $report = $this->superPdp->validate($facturx, "{$document->number}.pdf");
            $files['validation'] = Archive::put($folder, "{$document->number}-validation.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'application/json');
            $failures = array_merge($coherence['errors'], $this->validationFailures($report));

            $document->fill([
                'processing_rule' => $mapped['processing_rule'],
                'type_code' => $mapped['type_code'],
                'client_siren' => $mapped['buyer_siren'],
                'currency' => $mapped['invoice']['currency_code'],
                'directory_status' => $directory,
                'files' => array_merge($document->files ?? [], $files),
                'warnings' => $warnings,
                'errors' => $failures ?: null,
                'prepared_at' => now(),
                'next_attempt_at' => null,
            ]);

            if (! $dryRun) {
                $document->state = $failures ? OutboundDocument::INVALID : OutboundDocument::READY;
                $document->save();
                $failures
                    ? $this->blocked($document, $failures)
                    : Journal::info('outbound', 'prepared', "Factur-X générée et validée ({$mapped['processing_rule']}, type {$mapped['type_code']}).", $document, ['sha256' => $files['facturx']['sha256']]);
                $this->writeBack($document);
            }

            $result = ['ok' => ! $failures, 'errors' => $failures, 'warnings' => $warnings, 'mapped' => $mapped, 'files' => $files, 'report' => $report];
        } catch (MappingException $e) {
            $result['errors'] = [$e->getMessage()];

            if (! $dryRun) {
                $document->fill(['state' => OutboundDocument::INVALID, 'errors' => [$e->getMessage()], 'prepared_at' => now(), 'next_attempt_at' => null])->save();
                Journal::warning('outbound', 'mapping_failed', $e->getMessage(), $document);
                $this->writeBack($document);
                app(Supervisor::class)->blocked($document, [$e->getMessage()], 'données à compléter');
            }
        } catch (Throwable $e) {
            $result['errors'] = [$e->getMessage()];

            if (! $dryRun) {
                $this->failTechnically($document, 'prepare', $e);
            }
        }

        return $result;
    }

    /** Uploads the validated Factur-X. Safe to call twice: it never uploads twice. */
    public function send(OutboundDocument $document): bool
    {
        if ($document->superpdp_id) {
            return true;
        }

        if (! self::emissionEnabled()) {
            throw new RuntimeException('Émission désactivée (EINVOICING_ENABLED / EINVOICING_SEND_ENABLED).');
        }

        if ($document->state !== OutboundDocument::READY || ! $document->file('facturx')) {
            throw new RuntimeException("Le document {$document->number} n'est pas prêt (état : {$document->label()}).");
        }

        $lock = Cache::lock("outbound-send-{$document->id}", 300);

        if (! $lock->get()) {
            return false;
        }

        try {
            $document->refresh();

            if ($document->superpdp_id) {
                return true;
            }

            // A previous attempt may have reached SUPER PDP before failing locally.
            if ($document->attempts > 0 && ($found = $this->findUploaded($document))) {
                $this->markSubmitted($document, $found, 'retrouvée après une tentative interrompue');

                return true;
            }

            $facturx = Archive::get($document->file('facturx'));

            if (! Archive::verify($document->file('facturx'))) {
                throw new RuntimeException('Empreinte SHA-256 du Factur-X archivé invalide : fichier altéré, préparez à nouveau.');
            }

            $document->forceFill(['state' => OutboundDocument::SUBMITTING, 'attempts' => $document->attempts + 1])->save();

            try {
                $invoice = $this->superPdp->sendInvoice($facturx, $document->external_id, $document->processing_rule);
            } catch (SuperPdpException $e) {
                // Lost response after a successful upload, or a concurrent webhook deposit:
                // SUPER PDP answers « déjà existante ». Recover by external_id or the id in the message.
                if ($e->isPermanent() && str_contains($e->getMessage(), 'déjà existante') && ($found = $this->findUploaded($document) ?? $this->findUploadedByError($e->getMessage()))) {
                    $this->markSubmitted($document, $found, 'retrouvée après « déjà existante »');

                    return true;
                }

                throw $e;
            }
            $this->markSubmitted($document, $invoice);

            return true;
        } catch (SuperPdpException $e) {
            if ($e->isPermanent()) {
                $document->fill(['state' => OutboundDocument::INVALID, 'errors' => [$e->getMessage()], 'next_attempt_at' => null])->save();
                Journal::warning('outbound', 'send_refused', $e->getMessage(), $document);
                $this->writeBack($document);
                app(Supervisor::class)->blocked($document, [$e->getMessage()], 'refusée par SUPER PDP au dépôt');

                return false;
            }

            $this->failTechnically($document, 'send', $e, OutboundDocument::READY);

            return false;
        } catch (Throwable $e) {
            $this->failTechnically($document, 'send', $e, OutboundDocument::READY);

            return false;
        } finally {
            $lock->release();
        }
    }

    /** fr:212 "Encaissée": feeds payment e-reporting. Only once per document. */
    public function reportPayment(OutboundDocument $document, bool $force = false): bool
    {
        if (! $document->superpdp_id || $document->payment_reported_at) {
            return false;
        }

        if (! self::emissionEnabled() || (! $force && ! config('einvoicing.report_payments'))) {
            return false;
        }

        $doc = $this->invoiceNinja->invoice($document->in_id);

        if ((float) ($doc['balance'] ?? 1) != 0.0 || (int) ($doc['status_id'] ?? 0) !== 4) {
            return false;
        }

        $lock = Cache::lock("outbound-payment-{$document->id}", 120);

        if (! $lock->get()) {
            return false;
        }

        try {
            $document->refresh();

            if ($document->payment_reported_at) {
                return false;
            }

            // A previous attempt may have succeeded without us getting the answer.
            $history = collect($this->superPdp->invoice((int) $document->superpdp_id)['events'] ?? []);

            if ($history->contains(fn ($e) => ($e['status_code'] ?? null) === 'fr:212')) {
                $document->forceFill(['payment_reported_at' => now()])->save();
                Journal::info('outbound', 'payment_reported', 'Statut « Encaissée » (fr:212) déjà présent sur SUPER PDP.', $document);

                return false;
            }

            $event = $this->superPdp->createEvent((int) $document->superpdp_id, 'fr:212');
            $document->forceFill(['payment_reported_at' => now()])->save();
            Journal::info('outbound', 'payment_reported', 'Statut « Encaissée » (fr:212) transmis.', $document, ['event_id' => $event['id'] ?? null]);
            $this->note($document, 'statut « Encaissée » (fr:212) transmis à SUPER PDP.');

            return true;
        } finally {
            $lock->release();
        }
    }

    public function reportPayments(): int
    {
        if (! self::emissionEnabled() || ! config('einvoicing.report_payments')) {
            return 0;
        }

        $count = 0;

        OutboundDocument::query()
            ->where('entity_type', 'invoice')
            ->whereNotNull('superpdp_id')
            ->whereNull('payment_reported_at')
            ->whereIn('state', [OutboundDocument::SUBMITTED, OutboundDocument::DELIVERED])
            ->each(function (OutboundDocument $document) use (&$count) {
                try {
                    $count += $this->reportPayment($document) ? 1 : 0;
                } catch (Throwable $e) {
                    Journal::exception('outbound', 'payment_report_failed', $e, $document);
                }
            });

        return $count;
    }

    // --- Lifecycle ----------------------------------------------------------------

    public function applyEvent(OutboundDocument $document, array $event): void
    {
        $code = (string) $event['status_code'];
        $at = isset($event['created_at']) ? Carbon::parse($event['created_at']) : now();
        $previousState = $document->state;

        if ($document->last_status_at && $at->lt($document->last_status_at) && ! in_array($code, InvoiceEvent::REJECTION_CODES, true)) {
            return;
        }

        $document->last_status_code = $code;
        $document->last_status_text = InvoiceEvent::labelFor($code, $event['status_text'] ?? null);
        $document->last_status_at = $at;

        if (in_array($code, InvoiceEvent::REJECTION_CODES, true)) {
            $document->state = OutboundDocument::REJECTED;
            $document->rejection_reason = InvoiceEvent::reasonFrom($event) ?? $document->last_status_text;
        } elseif (in_array($code, InvoiceEvent::REFUSAL_CODES, true)) {
            $document->state = OutboundDocument::REFUSED;
            $document->rejection_reason = InvoiceEvent::reasonFrom($event) ?? $document->last_status_text;
        } elseif (in_array($code, InvoiceEvent::DELIVERY_CODES, true) && ! in_array($document->state, [OutboundDocument::REJECTED, OutboundDocument::REFUSED], true)) {
            $document->state = OutboundDocument::DELIVERED;
        }

        $document->save();

        $level = in_array($document->state, [OutboundDocument::REJECTED, OutboundDocument::REFUSED], true) ? 'warning' : 'info';
        $message = "{$code} {$document->last_status_text}".($document->rejection_reason && $level === 'warning' ? " : {$document->rejection_reason}" : '');
        Journal::{$level}('sync', 'status', $message, $document);
        $this->note($document, $message.' ('.$at->timezone('Europe/Paris')->format('d/m/Y H:i').').');

        $this->writeBack($document);

        if ($level === 'warning' && $previousState !== $document->state) {
            app(Supervisor::class)->rejected($document);
        }
    }

    /** Shows the e-invoice status in the Invoice Ninja custom fields, and attaches the Factur-X once. */
    public function writeBack(OutboundDocument $document): void
    {
        if (! config('einvoicing.invoice_ninja.write_back')) {
            return;
        }

        $fields = config('einvoicing.invoice_ninja.fields');
        $status = $document->label();

        if ($document->last_status_code) {
            $status = "{$document->last_status_code} {$document->last_status_text}";
        }

        $retry = config('einvoicing.invoice_ninja.action_retry');
        $detail = match (true) {
            filled($document->rejection_reason) => 'Motif : '.$document->rejection_reason,
            $document->state === OutboundDocument::INVALID => 'À corriger : '.implode(' | ', array_slice((array) $document->errors, 0, 3))." Puis « {$retry} ».",
            $document->state === OutboundDocument::ERROR || ($document->state === OutboundDocument::READY && $document->next_attempt_at !== null) => 'Erreur technique, nouvel essai automatique'.($document->next_attempt_at ? ' vers '.$document->next_attempt_at->timezone('Europe/Paris')->format('H:i') : '')." (ou « {$retry} »).",
            $document->state === OutboundDocument::READY && ! self::emissionEnabled() => 'Factur-X validée. Émission réelle verrouillée : non transmise tant que le dirigeant ne l\'a pas autorisée.',
            $document->superpdp_id !== null => 'Factur-X déposée : onglet Documents. Historique : onglet Activité.',
            default => 'Synchro '.now()->timezone('Europe/Paris')->format('d/m/Y H:i'),
        };

        $values = array_filter([
            $fields['status'] ?? null => mb_strimwidth($status.($document->last_status_at ? ' ('.$document->last_status_at->timezone('Europe/Paris')->format('d/m/Y H:i').')' : ''), 0, 190, '…'),
            $fields['reference'] ?? null => $document->superpdp_id
                ? "SuperPDP #{$document->superpdp_id}".($document->submitted_at ? ' — transmise le '.$document->submitted_at->timezone('Europe/Paris')->format('d/m/Y H:i') : '')
                : '',
            $fields['detail'] ?? null => mb_strimwidth($detail, 0, 190, '…'),
        ], fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH);

        $comparable = $values;
        unset($comparable[$fields['detail'] ?? '']);

        try {
            if ($this->fieldsNeedRefresh($document, $comparable)) {
                try {
                    $this->invoiceNinja->update(self::ENTITY_ENDPOINTS[$document->entity_type], $document->in_id, $values);
                    $document->forceFill(['in_fields' => $values, 'in_synced_at' => now()])->save();
                } catch (InvoiceNinjaException $e) {
                    if (! $this->invoiceIsLocked($e)) {
                        throw $e;
                    }

                    // Invoice Ninja refuses every field update on a locked invoice (HTTP 422).
                    // The amounts, lines and tax stay untouched. The status is appended to the activity history.
                    $this->rememberLockedStatus($document, $status, $comparable);
                }
            }

            $this->attachFacturXOnce($document);
        } catch (Throwable $e) {
            Journal::exception('invoiceninja', 'write_back_failed', $e, $document);
        }
    }

    /** @param  array<string, string>  $comparable */
    private function fieldsNeedRefresh(OutboundDocument $document, array $comparable): bool
    {
        $current = array_intersect_key((array) $document->in_fields, $comparable);
        $stale = $document->in_synced_at === null || $document->in_synced_at->lt(now()->subDay());
        $marker = (string) (($document->in_fields ?? [])['_locked_note'] ?? '');

        if ($marker !== '' && $marker === $this->lockedMarker($comparable) && ! $stale) {
            return false;
        }

        return $comparable !== $current || $stale;
    }

    /** @param  array<string, string>  $comparable */
    private function rememberLockedStatus(OutboundDocument $document, string $status, array $comparable): void
    {
        $marker = $this->lockedMarker($comparable);
        $fields = (array) $document->in_fields;

        if (($fields['_locked_note'] ?? '') === $marker) {
            return;
        }

        $reference = $document->superpdp_id ? " SuperPDP #{$document->superpdp_id}." : '';

        try {
            $this->invoiceNinja->addNote(
                self::ENTITY_ENDPOINTS[$document->entity_type],
                $document->in_id,
                'E-facture : facture verrouillée dans Invoice Ninja. Statut « '.$status.' » consigné ici, sans modification des montants.'.$reference,
            );
        } catch (Throwable $e) {
            Journal::exception('invoiceninja', 'locked_note_failed', $e, $document);

            return;
        }

        $fields['_locked_note'] = $marker;
        $document->forceFill(['in_fields' => $fields, 'in_synced_at' => now()])->save();
        Journal::info('invoiceninja', 'locked_invoice', "Facture {$document->number} verrouillée : statut consigné dans l'historique, montants inchangés.", $document);
    }

    /** @param  array<string, string>  $comparable */
    private function lockedMarker(array $comparable): string
    {
        return hash('sha256', (string) json_encode($comparable));
    }

    private function invoiceIsLocked(InvoiceNinjaException $e): bool
    {
        if ($e->getCode() !== 422) {
            return false;
        }

        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'locked') || str_contains($message, 'verrouill');
    }

    /**
     * Attaches the Factur-X once. A repeated sync, a retry, a second process or a lost
     * HTTP response finds the file already present and does not upload it again.
     */
    private function attachFacturXOnce(OutboundDocument $document): void
    {
        if (! $document->superpdp_id || ! config('einvoicing.invoice_ninja.upload_documents') || ! $document->file('facturx')) {
            return;
        }

        $filename = "{$document->number}-facturx-superpdp-{$document->superpdp_id}.pdf";
        $lock = Cache::lock('in-upload-'.sha1($document->entity_type.'|'.$document->in_id.'|'.$filename), 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $document->refresh();
            $files = $document->files ?? [];

            if ($files['facturx']['uploaded_to_in'] ?? false) {
                return;
            }

            $entity = self::ENTITY_ENDPOINTS[$document->entity_type];

            if (! $this->documentAlreadyAttached($entity, $document->in_id, $filename)) {
                $this->invoiceNinja->upload($entity, $document->in_id, $filename, Archive::get($document->file('facturx')));
            }

            $document->refresh();
            $files = $document->files ?? [];
            $files['facturx']['uploaded_to_in'] = now()->toIso8601String();
            $document->forceFill(['files' => $files])->save();
        } catch (Throwable $e) {
            if ($this->documentAlreadyAttached(self::ENTITY_ENDPOINTS[$document->entity_type], $document->in_id, $filename)) {
                $document->refresh();
                $files = $document->files ?? [];
                $files['facturx']['uploaded_to_in'] = now()->toIso8601String();
                $document->forceFill(['files' => $files])->save();

                return;
            }

            Journal::exception('invoiceninja', 'upload_failed', $e, $document);
        } finally {
            $lock->release();
        }
    }

    private function documentAlreadyAttached(string $entity, string $id, string $filename): bool
    {
        try {
            foreach ($this->invoiceNinja->documents($entity, $id) as $existing) {
                if (($existing['name'] ?? '') === $filename) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    // --- Helpers --------------------------------------------------------------------

    private function precedingFor(OutboundDocument $document, array $doc): array
    {
        if ($document->entity_type !== 'credit') {
            return [];
        }

        $invoiceId = $doc['invoice_id'] ?? null;

        if (filled($invoiceId)) {
            $invoice = $this->invoiceNinja->invoice($invoiceId);
        } else {
            // Invoice Ninja only links a credit to a paid invoice ("reverse"); otherwise the
            // original invoice number is typed in the credit's PO number field.
            $reference = trim((string) ($doc['po_number'] ?? ''));
            $invoice = $reference !== '' ? $this->invoiceNinja->findInvoiceByNumber($reference) : null;

            if (! $invoice || ($invoice['number'] ?? null) !== $reference || ($invoice['client_id'] ?? null) !== ($doc['client_id'] ?? null)) {
                return [];
            }
        }

        return [['reference' => $invoice['number'], 'issue_date' => $invoice['date']]];
    }

    /**
     * The structured data must say the same thing as the PDF the client reads.
     * Errors block the transmission; warnings are shown but do not.
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function coherence(array $doc, array $mapped, array $pdfSeller = []): array
    {
        $errors = [];
        $warnings = self::sellerWarnings($pdfSeller, $mapped['invoice']['seller']);
        $readable = mb_strtolower(implode("\n", [$doc['terms'] ?? '', $doc['footer'] ?? '', $doc['public_notes'] ?? '']));
        $flat = preg_replace('/[\s\x{2010}-\x{2015}.,°\-]+/u', '', $readable);
        $exemption = (string) config('einvoicing.vat.exemption_text');
        $code = (string) config('einvoicing.vat.exemption_code');
        $hasExempt = collect($mapped['invoice']['vat_break_down'])->contains(fn ($g) => $g['vat_category_code'] === 'E');

        if ($hasExempt) {
            if ($exemption !== '' && ! str_contains($flat, preg_replace('/[\s\x{2010}-\x{2015}.,°\-]+/u', '', mb_strtolower($exemption)))) {
                $errors[] = "Le PDF ne contient pas la mention d'exonération déclarée dans la facture électronique : « {$exemption} ».";
            }

            $cites293 = str_contains($flat, '293b');
            $cites261 = (bool) preg_match('/(art|article)261/', $flat);

            if (str_contains($code, 'FRANCHISE') && $cites261) {
                $errors[] = 'Le PDF cite l\'article 261 du CGI alors que la facture électronique déclare la franchise en base (293 B) : les deux doivent concorder.';
            }

            if (! str_contains($code, 'FRANCHISE') && $cites293) {
                $errors[] = "Le PDF cite la franchise en base (293 B) alors que la facture électronique déclare le motif {$code} : les deux doivent concorder.";
            }
        }

        if ($mapped['processing_rule'] === 'B2B') {
            if (! str_contains($readable, '40') || ! str_contains($readable, 'recouvrement')) {
                $errors[] = 'Mention légale absente du PDF : indemnité forfaitaire de recouvrement de 40 € (art. L441-10 C. com.).';
            }

            if (! str_contains($readable, 'pénalité') && ! str_contains($readable, 'penalite')) {
                $errors[] = 'Mention légale absente du PDF : taux des pénalités de retard (art. L441-10 C. com.).';
            }

            if (! str_contains($readable, 'escompte')) {
                $errors[] = 'Mention légale absente du PDF : conditions d\'escompte (art. L441-9 C. com.).';
            }
        }

        $structured = round((float) $mapped['total_with_vat'], 2);

        if (abs($structured - round((float) ($doc['amount'] ?? 0), 2)) > 0.001) {
            $errors[] = sprintf('Total TTC différent entre Invoice Ninja (%.2f) et la facture électronique (%.2f).', $doc['amount'] ?? 0, $structured);
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** The PDF and the structured data of a Factur-X must describe the same seller. */
    public static function sellerWarnings(array $pdf, array $seller): array
    {
        $words = fn (?string $s) => array_filter(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii((string) $s))));
        $warnings = [];

        if (filled($pdf['name'] ?? null) && array_diff($words($seller['name'] ?? ''), $words($pdf['name'])) !== []) {
            $warnings[] = "Vendeur : le PDF indique « {$pdf['name']} » alors que la facture électronique porte la dénomination légale « {$seller['name']} ». Faites figurer la dénomination légale (et la mention « EI » pour une entreprise individuelle) dans Paramètres → Informations sur l'entreprise.";
        }

        $address = $seller['postal_address'] ?? [];
        $number = fn (?string $s) => Str::lower((string) preg_replace('/^\s*(\d+\s*[a-z]?)\b.*$/iu', '$1', (string) $s));

        if (filled($pdf['address_line1'] ?? null) && filled($address['address_line1'] ?? null)
            && ($number($pdf['address_line1']) !== $number($address['address_line1']) || preg_replace('/\D/', '', (string) ($pdf['post_code'] ?? '')) !== preg_replace('/\D/', '', (string) ($address['post_code'] ?? '')))) {
            $warnings[] = "Vendeur : l'adresse du PDF (« {$pdf['address_line1']} ») diffère de l'adresse enregistrée de la facture électronique (« {$address['address_line1']} »).";
        }

        return $warnings;
    }

    private function checkDirectory(array &$mapped, bool $sandbox, array &$warnings): string
    {
        if ($mapped['processing_rule'] !== 'B2B') {
            return 'not_required';
        }

        if ($sandbox) {
            return 'sandbox';
        }

        $siren = $mapped['buyer_siren'];
        $address = $mapped['buyer_electronic_address'];

        try {
            $entries = $this->superPdp->frenchDirectoryEntries($siren);
        } catch (Throwable $e) {
            $warnings[] = 'Annuaire indisponible : '.$e->getMessage();

            return 'unknown';
        }

        $active = collect($entries)->where('is_active', true)->pluck('identifier')->all();

        if ($active === []) {
            throw new MappingException("Le client (SIREN {$siren}) n'a aucune adresse active dans l'annuaire de la facturation électronique : il n'est peut-être pas assujetti à la TVA (association, organisme public…). Vérifiez sa situation ; s'il n'est pas concerné, classez-le « Particulier » pour une transmission en e-reporting.");
        }

        // No routing id in Invoice Ninja and a single active address: the directory is authoritative.
        if (! in_array($address, $active, true) && $address === "0225:{$siren}" && count($active) === 1) {
            [$scheme, $value] = explode(':', $active[0], 2);
            $mapped['invoice']['buyer']['electronic_address'] = ['scheme' => $scheme, 'value' => $value];
            $mapped['buyer_electronic_address'] = $active[0];
            $warnings[] = "Adresse de facturation électronique du client prise dans l'annuaire : {$active[0]} (renseignez-la dans le champ « Routing ID » du client pour la figer).";

            return 'active';
        }

        if (! in_array($address, $active, true)) {
            throw new MappingException("L'adresse électronique {$address} n'est pas active dans l'annuaire. Adresses actives du client : ".implode(', ', $active).'. Renseignez la bonne dans le champ « Routing ID » du client.');
        }

        return 'active';
    }

    private function validationFailures(array $report): array
    {
        if (($report['is_valid'] ?? false) === true) {
            return [];
        }

        $failures = [];

        if (filled($report['error'] ?? null)) {
            $failures[] = $report['error'];
        }

        foreach ((array) ($report['subreports'] ?? []) as $sub) {
            foreach ((array) ($sub['failures'] ?? []) as $failure) {
                $failures[] = trim(($failure['message'] ?? $failure['raw'] ?? '').(filled($failure['location'] ?? null) ? ' ['.$failure['location'].']' : ''));
            }
        }

        return $failures ?: ['Factur-X invalide (rapport sans détail).'];
    }

    private function findUploaded(OutboundDocument $document): ?array
    {
        foreach ($this->superPdp->recentOutgoing() as $invoice) {
            if (($invoice['external_id'] ?? null) === $document->external_id) {
                return $invoice;
            }
        }

        return null;
    }

    /** Parses « La facture est déjà existante (id 123) » when the list lookup is empty. */
    private function findUploadedByError(string $message): ?array
    {
        if (! preg_match('/\bid\s+(\d+)\b/i', $message, $m)) {
            return null;
        }

        try {
            return $this->superPdp->invoice((int) $m[1]);
        } catch (Throwable) {
            return null;
        }
    }

    private function markSubmitted(OutboundDocument $document, array $invoice, string $note = ''): void
    {
        $events = (array) ($invoice['events'] ?? []);
        $last = end($events) ?: null;

        $document->forceFill([
            'superpdp_id' => $invoice['id'],
            'state' => OutboundDocument::SUBMITTED,
            'submitted_at' => now(),
            'next_attempt_at' => null,
            'errors' => null,
            'last_status_code' => $last['status_code'] ?? 'api:uploaded',
            'last_status_text' => InvoiceEvent::labelFor($last['status_code'] ?? 'api:uploaded', $last['status_text'] ?? null),
            'last_status_at' => isset($last['created_at']) ? Carbon::parse($last['created_at']) : now(),
        ])->save();

        Journal::info('outbound', 'submitted', "Déposée sur SUPER PDP (#{$invoice['id']}, {$this->superPdp->environment()})".($note ? " — {$note}" : '').'.', $document, ['external_id' => $document->external_id]);
        $this->note($document, "transmise à SUPER PDP le {$document->submitted_at->timezone('Europe/Paris')->format('d/m/Y H:i')} (réf. SuperPDP #{$invoice['id']}, {$this->superPdp->environment()}).");

        $this->writeBack($document);
    }

    private function blocked(OutboundDocument $document, array $failures): void
    {
        $message = 'Facture bloquée (contrôles de cohérence ou validateur) : '.implode(' | ', array_slice($failures, 0, 5));
        Journal::warning('outbound', 'validation_failed', $message, $document);
        $this->note($document, mb_strimwidth($message, 0, 900, '…'));
        app(Supervisor::class)->blocked($document, $failures, 'contrôles avant dépôt');
    }

    /** Best effort: the connector's own journal stays the reference. */
    private function note(OutboundDocument $document, string $text): void
    {
        if (! config('einvoicing.invoice_ninja.write_back') || ! config('einvoicing.invoice_ninja.activity_notes', true)) {
            return;
        }

        try {
            $this->invoiceNinja->addNote(self::ENTITY_ENDPOINTS[$document->entity_type], $document->in_id, 'E-facture : '.$text);
        } catch (Throwable $e) {
            Journal::exception('invoiceninja', 'note_failed', $e, $document);
        }
    }

    private function failTechnically(OutboundDocument $document, string $step, Throwable $e, string $state = OutboundDocument::ERROR): void
    {
        $attempts = $document->attempts + ($step === 'prepare' ? 1 : 0);
        $delay = min(360, 5 * (2 ** min($attempts, 6)));

        $document->forceFill([
            'state' => $step === 'send' ? $state : OutboundDocument::ERROR,
            'attempts' => $attempts,
            'errors' => [Journal::mask($e->getMessage())],
            'next_attempt_at' => now()->addMinutes($delay),
        ])->save();

        Journal::exception('outbound', "{$step}_failed", $e, $document, ['retry_in_minutes' => $delay]);
    }
}
