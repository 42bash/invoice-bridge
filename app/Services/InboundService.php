<?php

namespace App\Services;

use App\Models\InboundDocument;
use App\Models\InvoiceEvent;
use App\Models\SyncCursor;
use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\InvoiceNinja\Statics;
use App\Services\SuperPdp\SuperPdpClient;
use App\Support\Archive;
use App\Support\Journal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * SUPER PDP → Invoice Ninja (supplier invoices).
 *
 *  poll():   lists received invoices, archives the original file untouched and a
 *            readable Factur-X, detects duplicates
 *  import(): finds or creates the vendor, creates the expense, attaches the files
 *
 * Supplier invoices never touch the customer invoice tables or folders.
 */
class InboundService
{
    /** Statuses a buyer may send. fr:210 requires a reason. */
    public const BUYER_STATUSES = [
        'fr:204' => 'Prise en charge',
        'fr:205' => 'Approuvée',
        'fr:207' => 'En litige',
        'fr:210' => 'Refusée',
    ];

    public function __construct(
        private readonly SuperPdpClient $superPdp,
        private readonly InvoiceNinjaClient $invoiceNinja,
        private readonly Statics $statics,
    ) {}

    /** @return int number of new documents received */
    public function poll(): int
    {
        $count = 0;
        $after = (int) SyncCursor::get('superpdp.inbound.after', 0);

        do {
            $page = $this->superPdp->invoices('in', $after);

            foreach ($page['data'] ?? [] as $overview) {
                if (! InboundDocument::query()->where('superpdp_id', $overview['id'])->exists()) {
                    $this->receive((int) $overview['id']);
                    $count++;
                }

                $after = max($after, (int) $overview['id']);
                SyncCursor::put('superpdp.inbound.after', $after);
            }
        } while (($page['has_after'] ?? false) && ! empty($page['data']));

        return $count;
    }

    /** Fetches, archives and records one received invoice. Idempotent. */
    public function receive(int $superPdpId): InboundDocument
    {
        $existing = InboundDocument::query()->where('superpdp_id', $superPdpId)->first();

        if ($existing) {
            return $existing;
        }

        $invoice = $this->superPdp->invoice($superPdpId);
        $en = $invoice['en_invoice'] ?? [];
        $seller = $en['seller'] ?? [];
        $totals = $en['totals'] ?? [];
        $siren = $this->sellerSiren($seller);

        $folder = 'inbound/'.$superPdpId;
        $files = [];

        $original = $this->superPdp->invoiceFile($superPdpId, 'original');
        $originalType = $this->detectType($original->body(), $original->header('Content-Type'));
        $files['original'] = Archive::put($folder, "original.{$originalType['ext']}", $original->body(), $originalType['mime']);

        if ($originalType['ext'] !== 'pdf') {
            $readable = $this->superPdp->invoiceFile($superPdpId, 'factur-x')->body();
            $files['readable'] = Archive::put($folder, 'lisible-facturx.pdf', $readable, 'application/pdf');
        }

        $files['en16931'] = Archive::put($folder, 'en16931.json', json_encode($invoice, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json');

        $sellerKey = $siren ?: ($seller['vat_identifier'] ?? null) ?: mb_strtolower(trim((string) ($seller['name'] ?? '')));
        $fingerprint = InboundDocument::fingerprintFor($sellerKey, (string) ($en['number'] ?? ''), (string) ($en['issue_date'] ?? ''));
        $duplicateOf = InboundDocument::query()->where('fingerprint', $fingerprint)->orderBy('id')->first();
        $events = (array) ($invoice['events'] ?? []);
        $last = end($events) ?: null;

        $document = InboundDocument::create([
            'superpdp_id' => $superPdpId,
            'seller_name' => $seller['name'] ?? null,
            'seller_siren' => $siren,
            'seller_vat' => $seller['vat_identifier'] ?? null,
            'number' => $en['number'] ?? null,
            'type_code' => isset($en['type_code']) ? (int) $en['type_code'] : null,
            'issue_date' => $en['issue_date'] ?? null,
            'due_date' => $en['payment_due_date'] ?? null,
            'currency' => $en['currency_code'] ?? null,
            'total_without_vat' => $totals['total_without_vat'] ?? null,
            'total_vat' => $totals['total_vat_amount']['value'] ?? 0,
            'total_with_vat' => $totals['total_with_vat'] ?? null,
            'amount_due' => $totals['amount_due_for_payment'] ?? null,
            'fingerprint' => $fingerprint,
            'duplicate_of_id' => $duplicateOf?->id,
            'state' => $duplicateOf ? InboundDocument::DUPLICATE : InboundDocument::RECEIVED,
            'last_status_code' => $last['status_code'] ?? null,
            'last_status_text' => isset($last['status_code']) ? InvoiceEvent::labelFor($last['status_code'], $last['status_text'] ?? null) : null,
            'last_status_at' => isset($last['created_at']) ? Carbon::parse($last['created_at']) : null,
            'files' => $files,
            'summary' => $this->summarize($en, $invoice),
            'received_at' => isset($invoice['created_at']) ? Carbon::parse($invoice['created_at']) : now(),
        ]);

        $duplicateOf
            ? Journal::warning('inbound', 'duplicate', "Facture {$document->number} de {$document->seller_name} déjà reçue (document #{$duplicateOf->id}) : conservée, non importée.", $document)
            : Journal::info('inbound', 'received', "Facture fournisseur {$document->number} reçue de {$document->seller_name} ({$document->total_with_vat} {$document->currency}).", $document, ['sha256' => $files['original']['sha256']]);

        return $document;
    }

    /** Imports received documents into Invoice Ninja when automatic import is on. */
    public function importPending(): int
    {
        if (! config('einvoicing.auto_receive')) {
            return 0;
        }

        $count = 0;

        InboundDocument::query()
            ->whereIn('state', [InboundDocument::RECEIVED, InboundDocument::ERROR])
            ->orderBy('id')
            ->each(function (InboundDocument $document) use (&$count) {
                $count += $this->import($document) ? 1 : 0;
            });

        return $count;
    }

    /** Creates the vendor (if needed) and the expense in Invoice Ninja. Never twice. */
    public function import(InboundDocument $document, bool $force = false): bool
    {
        if ($document->in_expense_id) {
            return true;
        }

        if ($document->state === InboundDocument::DUPLICATE && ! $force) {
            return false;
        }

        $lock = Cache::lock("inbound-import-{$document->id}", 300);

        if (! $lock->get()) {
            return false;
        }

        try {
            $document->refresh();

            if ($document->in_expense_id) {
                return true;
            }

            $vendor = $this->resolveVendor($document);

            if (! $vendor) {
                return false;
            }

            $expense = $this->findExistingExpense($document, $vendor['id']);
            $linked = $expense !== null;
            $expense ??= $this->invoiceNinja->createExpense($this->expensePayload($document, $vendor));

            $document->forceFill([
                'in_vendor_id' => $vendor['id'],
                'in_expense_id' => $expense['id'],
                'state' => InboundDocument::IMPORTED,
                'imported_at' => now(),
                'errors' => null,
            ])->save();

            $linked
                ? Journal::warning('inbound', 'linked', "Une dépense existante correspond déjà à la facture {$document->number} : rattachée sans créer de doublon.", $document, ['expense_id' => $expense['id']])
                : Journal::info('inbound', 'imported', "Dépense créée dans Invoice Ninja pour la facture {$document->number} ({$vendor['name']}).", $document, ['expense_id' => $expense['id']]);

            $this->attachFiles($document);
            $this->note($document, "facture fournisseur {$document->number} reçue via SUPER PDP (#{$document->superpdp_id}) le "
                .($document->received_at ?? now())->timezone('Europe/Paris')->format('d/m/Y H:i')
                .'. Original archivé et joint (SHA-256 '.substr((string) ($document->files['original']['sha256'] ?? ''), 0, 16).'…).');

            return true;
        } catch (Throwable $e) {
            $document->forceFill(['state' => InboundDocument::ERROR, 'errors' => [Journal::mask($e->getMessage())]])->save();
            Journal::exception('inbound', 'import_failed', $e, $document);

            return false;
        } finally {
            $lock->release();
        }
    }

    public function ignore(InboundDocument $document, string $reason): void
    {
        if ($document->in_expense_id) {
            throw new RuntimeException('Document déjà importé dans Invoice Ninja.');
        }

        $document->forceFill(['state' => InboundDocument::IGNORED, 'errors' => [$reason]])->save();
        Journal::info('inbound', 'ignored', "Facture {$document->number} écartée : {$reason}", $document);
    }

    /**
     * Buyer lifecycle status (manual action from the dashboard only).
     * The reason code (fr:210) must come from the AFNOR XP Z12-012 list.
     */
    public function sendBuyerStatus(InboundDocument $document, string $code, ?string $reasonCode = null, ?string $note = null): array
    {
        if (! array_key_exists($code, self::BUYER_STATUSES)) {
            throw new InvalidArgumentException("Statut non autorisé : {$code}");
        }

        if (! config('einvoicing.enabled')) {
            throw new RuntimeException('Transmission désactivée (EINVOICING_ENABLED=false).');
        }

        if (in_array($code, ['fr:207', 'fr:210'], true) && blank($reasonCode)) {
            throw new InvalidArgumentException('Un code motif est obligatoire pour ce statut.');
        }

        $details = [];

        if (filled($reasonCode) || filled($note)) {
            $details[] = array_filter([
                'reason' => $reasonCode ?: null,
                'notes' => filled($note) ? [['subject' => 'AAI', 'contents' => [['content' => mb_substr($note, 0, 1000)]]]] : null,
            ]);
        }

        $event = $this->superPdp->createEvent((int) $document->superpdp_id, $code, $details);
        Journal::info('inbound', 'buyer_status', "Statut {$code} « ".self::BUYER_STATUSES[$code].' » transmis au fournisseur.', $document, ['event_id' => $event['id'] ?? null]);
        $this->note($document, "statut {$code} « ".self::BUYER_STATUSES[$code].' » transmis au fournisseur'.($reasonCode ? " (motif {$reasonCode})" : '').'.');

        return $event;
    }

    private function note(InboundDocument $document, string $text): void
    {
        if (! $document->in_expense_id || ! config('einvoicing.invoice_ninja.write_back') || ! config('einvoicing.invoice_ninja.activity_notes', true)) {
            return;
        }

        try {
            $this->invoiceNinja->addNote('expenses', $document->in_expense_id, 'E-facture : '.$text);
        } catch (Throwable $e) {
            Journal::exception('invoiceninja', 'note_failed', $e, $document);
        }
    }

    public function applyEvent(InboundDocument $document, array $event): void
    {
        $at = isset($event['created_at']) ? Carbon::parse($event['created_at']) : now();

        if ($document->last_status_at && $at->lt($document->last_status_at)) {
            return;
        }

        $document->forceFill([
            'last_status_code' => $event['status_code'],
            'last_status_text' => InvoiceEvent::labelFor($event['status_code'], $event['status_text'] ?? null),
            'last_status_at' => $at,
        ])->save();

        $this->writeBack($document);
    }

    public function writeBack(InboundDocument $document): void
    {
        if (! $document->in_expense_id || ! config('einvoicing.invoice_ninja.write_back')) {
            return;
        }

        $fields = config('einvoicing.invoice_ninja.expense_fields');
        $values = array_filter([
            $fields['reference'] ?? null => "SuperPDP #{$document->superpdp_id}",
            $fields['status'] ?? null => mb_strimwidth(trim(($document->last_status_code ?? 'reçue').' '.($document->last_status_text ?? '')), 0, 190, '…'),
        ], fn ($v, $k) => filled($k), ARRAY_FILTER_USE_BOTH);

        try {
            $this->invoiceNinja->update('expenses', $document->in_expense_id, $values);
        } catch (Throwable $e) {
            Journal::exception('invoiceninja', 'write_back_failed', $e, $document);
        }
    }

    // --- Helpers --------------------------------------------------------------------

    private function resolveVendor(InboundDocument $document): ?array
    {
        $candidates = [];

        if ($document->seller_siren) {
            $candidates = array_values(array_filter(
                $this->invoiceNinja->searchVendors($document->seller_siren),
                fn ($v) => str_starts_with(preg_replace('/\D/', '', (string) ($v['id_number'] ?? '')), $document->seller_siren)
                    || str_contains(preg_replace('/\s/', '', (string) ($v['vat_number'] ?? '')), $document->seller_siren),
            ));
        }

        if (! $candidates && $document->seller_vat) {
            $vat = strtoupper(preg_replace('/\s/', '', $document->seller_vat));
            $candidates = array_values(array_filter($this->invoiceNinja->allVendors(), fn ($v) => strtoupper(preg_replace('/\s/', '', (string) ($v['vat_number'] ?? ''))) === $vat));
        }

        if (! $candidates && $document->seller_name) {
            $name = $this->normalizeName($document->seller_name);
            $candidates = array_values(array_filter($this->invoiceNinja->allVendors(), fn ($v) => $this->normalizeName((string) ($v['name'] ?? '')) === $name));
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if (count($candidates) > 1) {
            $this->needsReview($document, 'Plusieurs fournisseurs correspondent dans Invoice Ninja ('.implode(', ', array_column($candidates, 'name')).') : choisissez-en un et importez manuellement.');

            return null;
        }

        if (! config('einvoicing.auto_create_vendors')) {
            $this->needsReview($document, "Fournisseur « {$document->seller_name} » inconnu dans Invoice Ninja (création automatique désactivée).");

            return null;
        }

        $vendor = $this->invoiceNinja->createVendor($this->vendorPayload($document));
        Journal::info('inbound', 'vendor_created', "Fournisseur « {$vendor['name']} » créé dans Invoice Ninja.", $document, ['vendor_id' => $vendor['id']]);

        return $vendor;
    }

    private function vendorPayload(InboundDocument $document): array
    {
        $seller = $this->enInvoice($document)['seller'] ?? [];
        $address = $seller['postal_address'] ?? [];
        $contact = $seller['contact'] ?? [];

        return array_filter([
            'name' => $seller['name'] ?? $document->seller_name,
            'id_number' => $document->seller_siren,
            'vat_number' => $seller['vat_identifier'] ?? null,
            'address1' => $address['address_line1'] ?? null,
            'address2' => trim(($address['address_line2'] ?? '').' '.($address['address_line3'] ?? '')) ?: null,
            'city' => $address['city'] ?? null,
            'postal_code' => $address['post_code'] ?? null,
            'country_id' => $this->statics->countryId($address['country_code'] ?? null),
            'currency_id' => $this->statics->currencyId($document->currency),
            'phone' => $contact['phone_number'] ?? null,
            'private_notes' => 'Créé automatiquement depuis une facture reçue via SUPER PDP.',
            'contacts' => filled($contact['email_address'] ?? null) ? [['email' => $contact['email_address'], 'first_name' => $contact['contact_point'] ?? '']] : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function findExistingExpense(InboundDocument $document, string $vendorId): ?array
    {
        $reference = "SuperPDP #{$document->superpdp_id}";
        $field = config('einvoicing.invoice_ninja.expense_fields.reference');

        foreach ($this->invoiceNinja->searchExpenses($reference) as $expense) {
            if (($expense[$field] ?? null) === $reference && ! ($expense['is_deleted'] ?? false)) {
                return $expense;
            }
        }

        if (blank($document->number)) {
            return null;
        }

        foreach ($this->invoiceNinja->expensesForVendor($vendorId) as $expense) {
            if (($expense['is_deleted'] ?? false) || $expense['vendor_id'] !== $vendorId) {
                continue;
            }

            // Older imports kept the supplier number in transaction_reference.
            if (trim((string) ($expense['transaction_reference'] ?? '')) === trim($document->number)
                || str_contains((string) ($expense['public_notes'] ?? ''), ' fournisseur '.trim($document->number).' du ')) {
                return $expense;
            }
        }

        return null;
    }

    private function expensePayload(InboundDocument $document, array $vendor): array
    {
        $en = $this->enInvoice($document);
        $fields = config('einvoicing.invoice_ninja.expense_fields');
        $credit = in_array($document->type_code, [381, 261, 262, 296, 308, 396, 502, 503], true);
        $sign = $credit ? -1 : 1;
        $taxes = [];

        foreach ((array) ($en['vat_break_down'] ?? []) as $group) {
            if ((float) ($group['vat_category_tax_amount'] ?? 0) == 0.0) {
                continue;
            }

            $taxes[] = ['name' => 'TVA '.rtrim(rtrim((string) $group['vat_category_rate'], '0'), '.').'%', 'rate' => (float) $group['vat_category_rate'], 'amount' => $sign * (float) $group['vat_category_tax_amount']];
        }

        $payload = [
            'vendor_id' => $vendor['id'],
            'amount' => $sign * (float) $document->total_with_vat,
            'date' => $document->issue_date?->toDateString(),
            'currency_id' => $this->statics->currencyId($document->currency),
            // No transaction_reference: Invoice Ninja shows any expense carrying one as "Paid".
            'uses_inclusive_taxes' => true,
            'calculate_tax_by_amount' => true,
            'public_notes' => trim(($credit ? 'Avoir' : 'Facture')." fournisseur {$document->number} du ".$document->issue_date?->format('d/m/Y').' reçue via SUPER PDP.'),
            'private_notes' => trim(implode("\n", array_filter([
                "SUPER PDP #{$document->superpdp_id} — HT {$document->total_without_vat} / TVA {$document->total_vat} / TTC {$document->total_with_vat} {$document->currency}",
                $document->due_date ? 'Échéance : '.$document->due_date->format('d/m/Y') : null,
                $document->amount_due !== null && (float) $document->amount_due !== (float) $document->total_with_vat ? "Net à payer : {$document->amount_due}" : null,
                count($taxes) > 3 ? 'Plus de 3 taux de TVA : seuls les 3 premiers sont ventilés, voir le document original.' : null,
            ]))),
            $fields['reference'] => "SuperPDP #{$document->superpdp_id}",
            $fields['status'] => trim(($document->last_status_code ?? 'reçue').' '.($document->last_status_text ?? '')),
        ];

        foreach (array_slice($taxes, 0, 3) as $i => $tax) {
            $n = $i + 1;
            $payload["tax_name{$n}"] = $tax['name'];
            $payload["tax_rate{$n}"] = $tax['rate'];
            $payload["tax_amount{$n}"] = $tax['amount'];
        }

        if ($category = $this->expenseCategoryId()) {
            $payload['category_id'] = $category;
        }

        return array_filter($payload, fn ($v) => $v !== null);
    }

    private function attachFiles(InboundDocument $document): void
    {
        if (! config('einvoicing.invoice_ninja.upload_documents') || ! $document->in_expense_id) {
            return;
        }

        $lock = Cache::lock('in-upload-expense-'.$document->in_expense_id, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $document->refresh();
            $files = $document->files ?? [];
            $present = [];

            try {
                foreach ($this->invoiceNinja->documents('expenses', $document->in_expense_id) as $existing) {
                    $present[] = (string) ($existing['name'] ?? '');
                }
            } catch (Throwable) {
                $present = [];
            }

            foreach (['original', 'readable'] as $kind) {
                $entry = $files[$kind] ?? null;

                if (! $entry) {
                    continue;
                }

                $name = preg_replace('/[^A-Za-z0-9._-]/', '_', "{$document->number}-{$kind}").'.'.pathinfo($entry['name'], PATHINFO_EXTENSION);

                if (($entry['uploaded_to_in'] ?? false) || in_array($name, $present, true)) {
                    $files[$kind]['uploaded_to_in'] = $entry['uploaded_to_in'] ?? now()->toIso8601String();

                    continue;
                }

                try {
                    $this->invoiceNinja->upload('expenses', $document->in_expense_id, $name, Archive::get($entry));
                    $files[$kind]['uploaded_to_in'] = now()->toIso8601String();
                    $present[] = $name;
                } catch (Throwable $e) {
                    $again = false;

                    try {
                        foreach ($this->invoiceNinja->documents('expenses', $document->in_expense_id) as $existing) {
                            $again = $again || ($existing['name'] ?? '') === $name;
                        }
                    } catch (Throwable) {
                    }

                    if ($again) {
                        $files[$kind]['uploaded_to_in'] = now()->toIso8601String();

                        continue;
                    }

                    Journal::exception('invoiceninja', 'upload_failed', $e, $document, ['file' => $kind]);
                }
            }

            $document->forceFill(['files' => $files])->save();
        } finally {
            $lock->release();
        }
    }

    private function expenseCategoryId(): ?string
    {
        $name = config('einvoicing.invoice_ninja.expense_category');

        if (blank($name)) {
            return null;
        }

        return Cache::remember('invoiceninja.expense_category.'.md5($name), now()->addHour(), function () use ($name) {
            try {
                foreach ($this->invoiceNinja->expenseCategories() as $category) {
                    if (mb_strtolower($category['name']) === mb_strtolower($name)) {
                        return $category['id'];
                    }
                }
            } catch (Throwable) {
            }

            return null;
        });
    }

    private function needsReview(InboundDocument $document, string $reason): void
    {
        $document->forceFill(['state' => InboundDocument::NEEDS_REVIEW, 'errors' => [$reason]])->save();
        Journal::warning('inbound', 'needs_review', $reason, $document);
    }

    private function sellerSiren(array $seller): ?string
    {
        $legal = $seller['legal_registration_identifier'] ?? [];
        $value = preg_replace('/\s/', '', (string) ($legal['value'] ?? ''));

        if (preg_match('/^\d{9}(\d{5})?$/', $value) && in_array($legal['scheme'] ?? '0002', ['0002', '0009', ''], true)) {
            return substr($value, 0, 9);
        }

        foreach ((array) ($seller['identifiers'] ?? []) as $identifier) {
            $id = preg_replace('/\s/', '', (string) ($identifier['value'] ?? ''));

            if (in_array($identifier['scheme'] ?? '', ['0002', '0009'], true) && preg_match('/^\d{9}/', $id)) {
                return substr($id, 0, 9);
            }
        }

        $address = $seller['electronic_address'] ?? [];

        if (($address['scheme'] ?? '') === '0225' && preg_match('/^(\d{9})/', (string) $address['value'], $m)) {
            return $m[1];
        }

        if (preg_match('/^FR[0-9A-Z]{2}(\d{9})$/', strtoupper(preg_replace('/\s/', '', (string) ($seller['vat_identifier'] ?? ''))), $m)) {
            return $m[1];
        }

        return null;
    }

    private function summarize(array $en, array $invoice): array
    {
        return [
            'processing_rule' => $invoice['processing_rule'] ?? null,
            'seller_address' => $en['seller']['postal_address'] ?? null,
            'seller_electronic_address' => $en['seller']['electronic_address'] ?? null,
            'buyer' => ['name' => $en['buyer']['name'] ?? null, 'siren' => $en['buyer']['legal_registration_identifier']['value'] ?? null],
            'vat_break_down' => $en['vat_break_down'] ?? [],
            'lines' => collect($en['lines'] ?? [])->map(fn ($l) => [
                'name' => $l['item_information']['name'] ?? null,
                'quantity' => $l['invoiced_quantity'] ?? null,
                'net_amount' => $l['net_amount'] ?? null,
                'vat_rate' => $l['vat_information']['invoiced_item_vat_rate'] ?? null,
            ])->take(50)->all(),
            'payment_terms' => $en['payment_terms'] ?? null,
            'preceding' => $en['preceding_invoice_references'] ?? [],
            'notes' => collect($en['notes'] ?? [])->pluck('note')->filter()->take(10)->values()->all(),
        ];
    }

    private function enInvoice(InboundDocument $document): array
    {
        $entry = $document->file('en16931');

        return $entry ? (json_decode(Archive::get($entry), true)['en_invoice'] ?? []) : [];
    }

    private function detectType(string $body, ?string $contentType): array
    {
        if (str_starts_with($body, '%PDF')) {
            return ['ext' => 'pdf', 'mime' => 'application/pdf'];
        }

        $head = ltrim(substr($body, 0, 2000));

        if (str_starts_with($head, '<') || str_contains((string) $contentType, 'xml')) {
            return ['ext' => 'xml', 'mime' => 'application/xml'];
        }

        return ['ext' => 'bin', 'mime' => $contentType ?: 'application/octet-stream'];
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\b(sas|sasu|sarl|eurl|sa|sci|snc|ei|eirl)\b\.?/u', '', $name);

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name));
    }
}
