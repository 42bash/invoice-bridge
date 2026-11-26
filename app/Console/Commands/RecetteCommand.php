<?php

namespace App\Console\Commands;

use App\Models\InboundDocument;
use App\Models\OutboundDocument;
use App\Models\SyncCursor;
use App\Services\EventSync;
use App\Services\InboundService;
use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\InvoiceNinja\Statics;
use App\Services\OutboundService;
use App\Services\SandboxPeer;
use App\Services\SuperPdp\SuperPdpClient;
use App\Services\SuperPdp\SuperPdpException;
use App\Support\Archive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * End-to-end acceptance run between the two SUPER PDP sandbox companies,
 * against the isolated Invoice Ninja TEST instance. Refuses to start unless
 * SUPER PDP is in sandbox, EINVOICING_TEST_INSTANCE=true and the Invoice
 * Ninja company name contains "TEST": it creates fictitious invoices.
 */
class RecetteCommand extends Command
{
    protected $signature = 'einvoicing:recette
        {--only= : scénarios à jouer, ex. S1,S2,S3}
        {--timeout=240 : attente maximale (secondes) des traitements asynchrones SUPER PDP}';

    protected $description = 'Bac à sable + instance Invoice Ninja de TEST uniquement : scénarios de recette bout en bout';

    /** Invoice Ninja currency id of EUR. */
    private const EUR = '3';

    private array $results = [];

    private array $ctx = [];

    public function __construct(
        private readonly InvoiceNinjaClient $in,
        private readonly SuperPdpClient $spdp,
        private readonly SandboxPeer $peer,
        private readonly OutboundService $out,
        private readonly InboundService $inbound,
        private readonly EventSync $events,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->guard();
            $this->setup();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $only = array_filter(array_map('trim', explode(',', strtoupper((string) $this->option('only')))));

        foreach ($this->scenarios() as $id => [$title, $expected, $run]) {
            if ($only && ! in_array($id, $only, true)) {
                continue;
            }

            $this->play($id, $title, $expected, $run);
        }

        $path = $this->report();
        $failed = collect($this->results)->where('ok', false)->count();
        $this->newLine();
        $this->components->info(count($this->results) - $failed.'/'.count($this->results)." scénarios réussis. Rapport : {$path}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function guard(): void
    {
        if (config('einvoicing.superpdp.environment') !== 'sandbox') {
            throw new RuntimeException('Recette refusée : SUPERPDP_ENVIRONMENT doit valoir sandbox.');
        }

        if (! config('einvoicing.test_instance')) {
            throw new RuntimeException('Recette refusée : EINVOICING_TEST_INSTANCE=true est requis (instance Invoice Ninja de test uniquement).');
        }

        $company = $this->in->company();

        if (! str_contains(mb_strtoupper((string) ($company['settings']['name'] ?? '')), 'TEST')) {
            throw new RuntimeException('Recette refusée : le nom de la société Invoice Ninja doit contenir « TEST » (protection contre une exécution sur la production).');
        }

        ['me' => $me, 'peer' => $peer] = $this->peer->guard();
        $this->ctx += ['company' => $company, 'me' => $me, 'peer' => $peer];
        $this->components->twoColumnDetail('Invoice Ninja (test)', $company['settings']['name']);
        $this->components->twoColumnDetail('Émetteur sandbox', "{$me['formal_name']} ({$me['number']})");
        $this->components->twoColumnDetail('Partenaire sandbox', "{$peer['formal_name']} ({$peer['number']})");
    }

    /** Fictitious configuration and customers in the TEST instance; safe to run again. */
    private function setup(): void
    {
        ['me' => $meAddress, 'peer' => $peerAddress] = $this->peer->addresses();
        $peerRouting = "{$peerAddress['scheme']}:{$peerAddress['value']}";

        config([
            'einvoicing.enabled' => true,
            'einvoicing.send_enabled' => true,
            'einvoicing.auto_send' => false,
            'einvoicing.auto_receive' => true,
            'einvoicing.report_payments' => true,
            'einvoicing.send_from_date' => null,
            'einvoicing.invoice_ninja.write_back' => true,
            'einvoicing.superpdp.sandbox_buyer_address' => $peerRouting,
        ]);

        if (config('einvoicing.vat.franchise') && ($this->ctx['me']['vat_regime'] ?? '') !== 'vat_exemption') {
            $this->spdp->updateVatRegime('vat_exemption');
        }

        $company = $this->ctx['company'];
        $terms = implode("\n", [
            config('einvoicing.vat.exemption_text'),
            config('einvoicing.notes.PMD'),
            config('einvoicing.notes.PMT'),
            config('einvoicing.notes.AAB'),
        ]);
        $settings = array_merge($company['settings'], [
            'invoice_terms' => $terms,
            'credit_terms' => $terms,
            'id_number' => $this->ctx['me']['number'],
            'country_id' => '250',
            'currency_id' => self::EUR,
            'credit_number_pattern' => 'AV-{$counter}',
        ]);
        $this->in->update('companies', $company['id'], [
            'settings' => $settings,
            'custom_fields' => array_merge((array) ($company['custom_fields'] ?? []), SetupFieldsCommand::expected()),
        ]);
        $this->ctx['terms'] = $terms;

        $this->ctx['tricatel'] = $this->client("{$this->ctx['peer']['formal_name']} (TEST)", $this->ctx['peer']['number'], $peerRouting);
        $this->ctx['nosiren'] = $this->client('Client sans SIREN (TEST)', '', '');
        $this->ctx['particulier'] = $this->client('Particulier (TEST)', '', '', 'individual');
        $this->components->twoColumnDetail('Adresse de réception (vous)', "{$meAddress['scheme']}:{$meAddress['value']}");
        $this->components->twoColumnDetail('Adresse du partenaire', $peerRouting);
    }

    private function scenarios(): array
    {
        return [
            'S1' => ['Contrôle de l\'environnement', 'Sandbox confirmé, entrée d\'annuaire présente', function () {
                $me = $this->spdp->assertEnvironment();
                $entries = $this->spdp->ownDirectoryEntries();
                $this->check($me['env'] === 'sandbox', 'environnement '.$me['env']);
                $this->check($entries !== [], 'aucune entrée d\'annuaire pour l\'émetteur');

                return 'env='.$me['env'].', annuaire : '.implode(', ', array_map(fn ($e) => "{$e['directory']} {$e['identifier']} ({$e['status']})", $entries));
            }],
            'S2' => ['Émission B2B', 'Déposée, reçue par le partenaire, statuts recopiés dans Invoice Ninja', function () {
                $doc = $this->transmit($this->invoice($this->ctx['tricatel']));
                $received = $this->waitFor(fn () => $this->peerCopy($doc->number), 'réception par le partenaire');
                $this->waitFor(function () use ($doc) {
                    $this->events->run();

                    return $doc->refresh()->last_status_code;
                }, 'premier statut de cycle de vie');
                $in = $this->in->invoice($doc->in_id);
                $this->check(str_contains((string) ($in[config('einvoicing.invoice_ninja.fields.reference')] ?? ''), "#{$doc->superpdp_id}"), 'référence SuperPDP absente de la facture Invoice Ninja');
                $this->ctx['s2'] = $doc;
                $this->ctx['s2_peer_id'] = $received['id'];

                return "{$doc->number} → SuperPDP #{$doc->superpdp_id}, reçue côté partenaire #{$received['id']}, statut {$doc->last_status_code}, champ Invoice Ninja « {$in[config('einvoicing.invoice_ninja.fields.status')]} »";
            }],
            'S3' => ['Redépôt de la même facture', 'Aucun second dépôt', function () {
                $doc = $this->need('s2');
                $this->check($this->out->send($doc), 'send() a échoué');
                $this->out->upsert('invoice', $this->in->invoice($doc->in_id), true);
                $this->out->processQueue();
                Sleep::for(10)->seconds();
                $copies = collect($this->peer->inbox(100))->where('external_id', $doc->external_id)->count()
                    ?: collect($this->peer->inbox(100))->filter(fn ($i) => ($i['en_invoice']['number'] ?? null) === $doc->number)->count();
                $outgoing = collect($this->spdp->recentOutgoing())->where('external_id', $doc->external_id)->count();
                $this->check($outgoing === 1, "{$outgoing} dépôts sortants pour {$doc->external_id}");
                $this->check($copies <= 1, "{$copies} exemplaires reçus par le partenaire");

                return "1 seul dépôt sortant pour {$doc->external_id}, {$copies} exemplaire côté partenaire";
            }],
            'S4' => ['Avoir lié', 'Type 381 et référence à la facture d\'origine (impayée : n° saisi dans « N° de bon de commande »)', function () {
                $origin = $this->need('s2');
                $credit = $this->in->post('credits', [
                    'client_id' => $this->ctx['tricatel']['id'],
                    'po_number' => $origin->number,
                    'date' => now()->toDateString(),
                    'terms' => $this->ctx['terms'],
                    'line_items' => [$this->lineItem(20)],
                    config('einvoicing.invoice_ninja.fields.action') => config('einvoicing.invoice_ninja.action_send'),
                ]);
                $this->in->get("credits/{$credit['id']}/mark_sent");
                $doc = $this->transmit($this->in->credit($credit['id']), 'credit');
                $json = json_decode(Archive::get($doc->file('en16931')), true);
                $this->check($doc->type_code === 381, "type {$doc->type_code}");
                $this->check(($json['preceding_invoice_references'][0]['reference'] ?? null) === $origin->number, 'référence à la facture d\'origine absente');

                return "{$doc->number} type 381 → {$origin->number}, SuperPDP #{$doc->superpdp_id}";
            }],
            'S5' => ['Facture d\'acompte', 'Type 386', function () {
                $doc = $this->transmit($this->invoice($this->ctx['tricatel'], [config('einvoicing.invoice_ninja.fields.action') => config('einvoicing.invoice_ninja.action_send_deposit')]));
                $this->check($doc->type_code === 386, "type {$doc->type_code}");

                return "{$doc->number} type 386, SuperPDP #{$doc->superpdp_id}";
            }],
            'S6' => ['Facture invalide (client sans SIREN)', '« À corriger », rien n\'est déposé', function () {
                $doc = $this->out->upsert('invoice', $this->invoice($this->ctx['nosiren']), true);
                $this->out->prepare($doc);
                $doc->refresh();
                $this->check($doc->state === OutboundDocument::INVALID, "état {$doc->state}");
                $this->check($doc->superpdp_id === null, 'un dépôt a eu lieu');
                $this->check(! $this->out->maySend($doc) || $doc->state !== OutboundDocument::READY, 'document envoyable');
                $this->ctx['s6'] = $doc;

                return "{$doc->number} : ".implode(' | ', (array) $doc->errors);
            }],
            'S7' => ['Réception d\'une facture fournisseur', 'Original archivé, fournisseur et dépense créés', function () {
                $sent = $this->peer->sendToMe();
                $doc = $this->receiveNumber($sent['number']);
                $this->inbound->importPending();
                $doc->refresh();
                $this->check($doc->state === InboundDocument::IMPORTED, "état {$doc->state} ".json_encode($doc->errors, JSON_UNESCAPED_UNICODE));
                $this->check(Archive::verify($doc->files['original']), 'original absent ou altéré');
                $expense = $this->in->get("expenses/{$doc->in_expense_id}")['data'] ?? [];
                $this->check(($expense['vendor_id'] ?? null) === $doc->in_vendor_id, 'dépense non rattachée au fournisseur');
                // Invoice Ninja's "Paid" badge: payment date, payment type or transaction reference.
                $this->check(blank($expense['payment_date'] ?? null) && blank($expense['payment_type_id'] ?? null) && blank($expense['transaction_reference'] ?? null), 'dépense affichée « payée » dès sa réception');
                $documents = count($expense['documents'] ?? []);
                $this->check($documents >= 1, 'original non joint à la dépense');
                $this->ctx['s7'] = $doc;

                return "{$sent['number']} reçue (SuperPDP #{$doc->superpdp_id}), original sha256 ".substr($doc->files['original']['sha256'], 0, 12)."…, dépense {$doc->in_expense_id} ({$expense['amount']} €, non payée, {$documents} document(s) joint(s))";
            }],
            'S8' => ['Refus par le client', 'Statut « Refusée » et motif visibles dans Invoice Ninja', function () {
                $doc = $this->need('s2');
                $this->peer->respond((int) $this->ctx['s2_peer_id'], 'fr:210', 'TX_TVA_ERR', 'Recette : refus simulé');
                $this->waitFor(function () use ($doc) {
                    $this->events->run();

                    return $doc->refresh()->state === OutboundDocument::REFUSED;
                }, 'statut fr:210');
                $in = $this->in->invoice($doc->in_id);
                $detail = (string) ($in[config('einvoicing.invoice_ninja.fields.detail')] ?? '');
                $this->check($doc->rejection_reason !== null, 'motif non enregistré');

                return "{$doc->number} : {$doc->last_status_code}, motif « {$doc->rejection_reason} », Invoice Ninja « {$in[config('einvoicing.invoice_ninja.fields.status')]} » / « {$detail} »";
            }],
            'S9' => ['Coupure réseau et reprise', 'Curseur inchangé pendant la coupure, aucun doublon après reprise', function () {
                $before = SyncCursor::get('superpdp.inbound.after', 0);
                $broken = new InboundService(new SuperPdpClient('https://127.0.0.1:9', config('einvoicing.superpdp.client_id'), config('einvoicing.superpdp.client_secret'), 'sandbox', 5), $this->in, app(Statics::class));

                try {
                    $broken->poll();
                    $this->check(false, 'la coupure n\'a pas été détectée');
                } catch (Throwable) {
                }

                $this->check(SyncCursor::get('superpdp.inbound.after', 0) == $before, 'curseur modifié pendant la coupure');

                $doc = $this->out->upsert('invoice', $this->invoice($this->ctx['tricatel']), true);
                $this->out->prepare($doc);
                $doc->refresh();
                $this->check($doc->state === OutboundDocument::READY, "préparation : {$doc->state}");
                // The upload reaches SUPER PDP but the answer is lost. The Invoice Ninja
                // webhook may already have deposited the same Factur-X: both cases are
                // recovered by external_id (or by the id in a « déjà existante » error).
                try {
                    $this->spdp->sendInvoice(Archive::get($doc->file('facturx')), $doc->external_id, $doc->processing_rule);
                } catch (Throwable $e) {
                    $this->check(str_contains($e->getMessage(), 'déjà existante'), 'envoi simulé : '.$e->getMessage());
                }
                $doc->forceFill(['attempts' => max(1, (int) $doc->attempts)])->save();
                $this->out->send($doc->refresh());
                $doc->refresh();
                $count = collect($this->spdp->recentOutgoing())->where('external_id', $doc->external_id)->count();
                $this->check($doc->superpdp_id !== null, 'document non rattaché au dépôt existant');
                $this->check($count === 1, "{$count} dépôts pour {$doc->external_id}");

                return "poll interrompu sans effet (curseur {$before}) ; envoi interrompu retrouvé par external_id : {$doc->number} → SuperPDP #{$doc->superpdp_id}, 1 seul dépôt";
            }],
            'S10' => ['« Encaissée » (fr:212) sur facture soldée', 'Un seul événement, même après perte de la réponse', function () {
                $doc = $this->transmit($invoice = $this->invoice($this->ctx['tricatel']));
                $this->in->post('payments', [
                    'client_id' => $this->ctx['tricatel']['id'],
                    'amount' => $invoice['amount'],
                    'date' => now()->toDateString(),
                    'invoices' => [['invoice_id' => $invoice['id'], 'amount' => $invoice['amount']]],
                ]);
                $this->check($this->out->reportPayment($doc), 'fr:212 non transmis');
                $this->check(! $this->out->reportPayment($doc->refresh()), 'second fr:212 accepté');
                $this->waitFor(fn () => collect($this->spdp->invoice((int) $doc->superpdp_id)['events'] ?? [])->contains('status_code', 'fr:212'), 'fr:212 visible sur SUPER PDP');
                $doc->forceFill(['payment_reported_at' => null])->save();
                $this->out->reportPayment($doc->refresh());
                $count = collect($this->spdp->invoice((int) $doc->superpdp_id)['events'] ?? [])->where('status_code', 'fr:212')->count();
                $this->check($count === 1, "{$count} événements fr:212");

                return "{$doc->number} : 1 seul fr:212 malgré 3 demandes (dont une après perte simulée de la réponse)";
            }],
            'S11' => ['Doublon à la réception', 'Renvoi refusé par SUPER PDP ou marqué « Doublon » ; relecture complète sans seconde dépense', function () {
                $first = $this->need('s7');
                $expenses = count($this->in->expensesForVendor($first->in_vendor_id));

                try {
                    $this->peer->resend($first->number);
                    $dup = $this->waitFor(function () use ($first) {
                        $this->inbound->poll();

                        return InboundDocument::query()->where('number', $first->number)->where('id', '!=', $first->id)->first();
                    }, 'réception du doublon');
                    $this->inbound->importPending();
                    $this->check($dup->refresh()->state === InboundDocument::DUPLICATE, "état {$dup->state}");
                    $this->check($dup->in_expense_id === null, 'dépense créée pour le doublon');
                    $upstream = "renvoi accepté, SuperPDP #{$dup->superpdp_id} marquée doublon de #{$first->superpdp_id}";
                } catch (SuperPdpException $e) {
                    $this->check(str_contains($e->getMessage(), 'déjà existante'), $e->getMessage());
                    $upstream = 'renvoi refusé par SUPER PDP (« déjà existante »)';
                }

                // Replays the whole inbox from the start, as after a lost cursor.
                $cursor = SyncCursor::get('superpdp.inbound.after');
                SyncCursor::put('superpdp.inbound.after', 0);
                $new = $this->inbound->poll();
                SyncCursor::put('superpdp.inbound.after', max((int) $cursor, (int) SyncCursor::get('superpdp.inbound.after')));
                $this->inbound->importPending();
                $copies = InboundDocument::query()->where('number', $first->number)->where('state', '!=', InboundDocument::DUPLICATE)->count();
                $after = count($this->in->expensesForVendor($first->in_vendor_id));
                $this->check($new === 0, "{$new} document(s) recréé(s) à la relecture");
                $this->check($copies === 1, "{$copies} exemplaires actifs de {$first->number}");
                $this->check($after === $expenses, "{$expenses} → {$after} dépenses");

                return "{$upstream} ; relecture complète de la boîte : 0 nouveau document, {$after} dépense(s) avant et après";
            }],
            'S13' => ['Vente à un particulier (e-reporting)', 'Déposée en B2C, reprise dans l\'aperçu d\'e-reporting des transactions', function () {
                $before = $this->ereportingTotal('transaction')[0];
                $doc = $this->transmit($this->invoice($this->ctx['particulier'], ['line_items' => [$this->lineItem(73.5)]]));
                $this->check($doc->processing_rule === 'B2C', "cadre {$doc->processing_rule}");
                [$after, $xml] = $this->waitFor(function () use ($before) {
                    $total = $this->ereportingTotal('transaction');

                    return abs($total[0] - $before - 73.5) < 0.005 ? $total : null;
                }, 'transaction dans l\'aperçu d\'e-reporting');
                $this->ctx['s13'] = $doc;

                return "{$doc->number} → SuperPDP #{$doc->superpdp_id} (B2C) ; e-reporting transactions du jour : ".$this->ereportingSummary('transactions', $before, $after, $xml);
            }],
            'S14' => ['Encaissement d\'une vente à un particulier', 'Paiement transmis une seule fois, repris dans l\'aperçu d\'e-reporting des paiements', function () {
                $doc = $this->need('s13');
                $before = $this->ereportingTotal('payment')[0];
                $invoice = $this->in->invoice($doc->in_id);
                $this->in->post('payments', [
                    'client_id' => $this->ctx['particulier']['id'],
                    'amount' => $invoice['amount'],
                    'date' => now()->toDateString(),
                    'invoices' => [['invoice_id' => $invoice['id'], 'amount' => $invoice['amount']]],
                ]);
                $this->check($this->out->reportPayment($doc), 'encaissement non transmis');
                $this->check(! $this->out->reportPayment($doc->refresh()), 'second encaissement accepté');
                [$after, $xml] = $this->waitFor(function () use ($before) {
                    $total = $this->ereportingTotal('payment');

                    return abs($total[0] - $before - 73.5) < 0.005 ? $total : null;
                }, 'paiement dans l\'aperçu d\'e-reporting');

                return "{$doc->number} : fr:212 unique ; e-reporting paiements du jour : ".$this->ereportingSummary('paiements', $before, $after, $xml);
            }],
            'S15' => ['Relance depuis Invoice Ninja', 'Après correction du client, « '.config('einvoicing.invoice_ninja.action_retry').' » redépose la facture bloquée et le champ revient à « '.config('einvoicing.invoice_ninja.action_send').' »', function () {
                $doc = $this->need('s6');
                $field = config('einvoicing.invoice_ninja.fields.action');
                $client = $this->ctx['nosiren'];
                // As a SIRET: Invoice Ninja refuses an id_number already used by the Tricatel client.
                $this->in->update('clients', $client['id'], ['id_number' => $this->ctx['peer']['number'].'00018']);

                try {
                    $this->in->update('invoices', $doc->in_id, [$field => config('einvoicing.invoice_ninja.action_retry')]);
                    $this->out->upsert('invoice', $this->in->invoice($doc->in_id));
                    $doc->refresh();
                    // The Invoice Ninja webhook may already be preparing/sending this
                    // document (acknowledgeRetry writes the field back). Finish locally
                    // if the webhook has not deposited yet.
                    if ($doc->superpdp_id === null) {
                        $this->out->processOne($doc);
                        $doc->refresh();
                    }
                    $this->check($doc->superpdp_id !== null, "dépôt après relance : {$doc->state} ".implode(' | ', (array) $doc->errors));
                    $this->check(($this->in->invoice($doc->in_id)[$field] ?? null) === config('einvoicing.invoice_ninja.action_send'), 'champ non remis à « '.config('einvoicing.invoice_ninja.action_send').' »');
                } finally {
                    // S6 needs a client without SIREN on the next run.
                    $this->in->update('clients', $client['id'], ['id_number' => '']);
                }

                return "{$doc->number} : « À corriger » → SIREN saisi → « ".config('einvoicing.invoice_ninja.action_retry')." » → SuperPDP #{$doc->superpdp_id}, champ revenu à « ".config('einvoicing.invoice_ninja.action_send').' »';
            }],
            'S12' => ['Import rejoué après perte de la réponse', 'La dépense existante est retrouvée, aucune seconde dépense', function () {
                $doc = $this->need('s7');
                $expenseId = $doc->in_expense_id;
                $before = count($this->in->expensesForVendor($doc->in_vendor_id));
                $doc->forceFill(['in_expense_id' => null, 'state' => InboundDocument::RECEIVED])->save();
                $this->inbound->import($doc->refresh());
                $after = count($this->in->expensesForVendor($doc->in_vendor_id));
                $this->check($after === $before, "{$before} → {$after} dépenses");
                $this->check($doc->refresh()->in_expense_id === $expenseId, 'dépense existante non retrouvée');

                return "dépense {$expenseId} retrouvée, {$before} dépense(s) avant et après";
            }],
        ];
    }

    // --- Helpers ----------------------------------------------------------------

    private function play(string $id, string $title, string $expected, callable $run): void
    {
        $started = microtime(true);

        try {
            $evidence = $run();
            $ok = true;
        } catch (Throwable $e) {
            $evidence = $e->getMessage();
            $ok = false;
        }

        $this->results[] = compact('id', 'title', 'expected', 'evidence', 'ok') + ['seconds' => round(microtime(true) - $started)];
        $ok ? $this->components->twoColumnDetail("<fg=green>✔</> {$id} {$title}", mb_strimwidth($evidence, 0, 90, '…'))
            : $this->components->twoColumnDetail("<fg=red>✘</> {$id} {$title}", mb_strimwidth($evidence, 0, 90, '…'));
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    private function need(string $key): mixed
    {
        return $this->ctx[$key] ?? throw new RuntimeException("Prérequis manquant : scénario {$key} non réussi.");
    }

    private function waitFor(callable $probe, string $what): mixed
    {
        $deadline = time() + (int) $this->option('timeout');

        do {
            if ($result = $probe()) {
                return $result;
            }

            Sleep::for(5)->seconds();
        } while (time() < $deadline);

        throw new RuntimeException("Délai dépassé : {$what}.");
    }

    private function client(string $name, string $siren, string $routing, string $classification = 'business'): array
    {
        $existing = collect($this->in->get('clients', ['name' => $name, 'per_page' => 5])['data'] ?? [])->firstWhere('name', $name);

        return $existing ?? $this->in->post('clients', [
            'name' => $name,
            'classification' => $classification,
            'id_number' => $siren,
            'routing_id' => $routing,
            'country_id' => '250',
            'address1' => '1 rue de la Recette',
            'postal_code' => '75001',
            'city' => 'Paris',
            'contacts' => [['first_name' => 'Recette', 'email' => 'recette@example.invalid']],
        ]);
    }

    private function lineItem(float $amount): array
    {
        return ['product_key' => 'RECETTE', 'notes' => 'Prestation fictive de recette', 'cost' => $amount, 'quantity' => 1, 'tax_name1' => '', 'tax_rate1' => 0];
    }

    private function invoice(array $client, array $extra = []): array
    {
        $invoice = $this->in->post('invoices', array_merge([
            'client_id' => $client['id'],
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'terms' => $this->ctx['terms'],
            'line_items' => [$this->lineItem(100)],
            config('einvoicing.invoice_ninja.fields.action') => config('einvoicing.invoice_ninja.action_send'),
        ], $extra));
        $this->in->get("invoices/{$invoice['id']}/mark_sent");

        return $this->in->invoice($invoice['id']);
    }

    private function transmit(array $doc, string $kind = 'invoice'): OutboundDocument
    {
        $document = $this->out->upsert($kind, $doc, true);
        $this->out->prepare($document);
        $document->refresh();
        $this->check($document->state === OutboundDocument::READY, "préparation de {$document->number} : ".implode(' | ', (array) $document->errors));
        $this->out->send($document);
        $document->refresh();
        $this->check($document->superpdp_id !== null, "dépôt de {$document->number} : {$document->state} ".implode(' | ', (array) $document->errors));

        return $document;
    }

    /**
     * Today's amount in the e-reporting preview: SUPER PDP aggregates per day, so earlier
     * runs on the same day are already included.
     *
     * @return array{0: float, 1: string}
     */
    private function ereportingTotal(string $kind): array
    {
        $xml = (string) $this->spdp->ereportingPreview($kind, 'SE', now()->toDateString());
        $today = now()->format('Ymd');
        $total = 0.0;

        if ($xml !== '') {
            $report = new \SimpleXMLElement($xml);
            $nodes = $kind === 'payment'
                ? $report->xpath("//Payment[Date='{$today}']/SubTotals/Amount")
                : $report->xpath("//Transactions[Date='{$today}']/TaxExclusiveAmount");

            foreach ($nodes ?: [] as $node) {
                $total += (float) $node;
            }
        }

        return [round($total, 2), $xml];
    }

    /** Keeps the preview next to the report and returns a one-line summary. */
    private function ereportingSummary(string $kind, float $before, float $after, string $xml): string
    {
        $path = 'recette/ereporting-'.$kind.'-'.now()->format('Ymd-His').'.xml';
        File::ensureDirectoryExists(storage_path('app/recette'));
        File::put(storage_path('app/'.$path), $xml);

        return sprintf('%.2f → %.2f EUR (+73.50) (storage/app/%s)', $before, $after, $path);
    }

    /** The receiving side does not see the sender's external_id: match on the invoice number. */
    private function peerCopy(string $number): ?array
    {
        return collect($this->peer->inbox(50))->first(fn ($i) => ($i['en_invoice']['number'] ?? null) === $number);
    }

    private function receiveNumber(string $number): InboundDocument
    {
        return $this->waitFor(function () use ($number) {
            $this->inbound->poll();

            return InboundDocument::query()->where('number', $number)->orderBy('id')->first();
        }, "réception de {$number}");
    }

    private function report(): string
    {
        $lines = [
            '# Rapport de recette e-facturation (sandbox SUPER PDP)',
            '',
            '- Date : '.now()->toDateTimeString(),
            "- Invoice Ninja : instance de TEST « {$this->ctx['company']['settings']['name']} »",
            "- Émetteur : {$this->ctx['me']['formal_name']} ({$this->ctx['me']['number']}), partenaire : {$this->ctx['peer']['formal_name']} ({$this->ctx['peer']['number']})",
            '- Données exclusivement fictives, aucun identifiant ni secret dans ce rapport.',
            '',
            '| # | Scénario | Attendu | Résultat | Preuve |',
            '|---|---|---|---|---|',
        ];

        foreach ($this->results as $r) {
            $lines[] = sprintf('| %s | %s | %s | %s | %s |', $r['id'], $r['title'], $r['expected'], $r['ok'] ? 'Réussi' : '**Échec**', str_replace(['|', "\n"], ['/', ' '], $r['evidence']));
        }

        $path = storage_path('app/recette/recette-'.now()->format('Ymd-His').'.md');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", $lines)."\n");

        return $path;
    }
}
