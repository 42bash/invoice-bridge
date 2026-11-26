<?php

namespace Tests\Feature;

use App\Models\ExchangeLog;
use App\Models\OutboundDocument;
use App\Services\EventSync;
use App\Services\OutboundService;
use App\Services\SuperPdp\SuperPdpClient;
use App\Support\Archive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\Fixtures;
use Tests\TestCase;

class OutboundServiceTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureConnector();
    }

    private function service(): OutboundService
    {
        return app(OutboundService::class);
    }

    private function readyDocument(): OutboundDocument
    {
        $document = $this->service()->upsert('invoice', $this->inInvoice());
        $this->service()->prepare($document);

        return $document->refresh();
    }

    public function test_discovery_only_tracks_issued_recent_invoices(): void
    {
        $this->fakeHttp();

        $this->assertNull($this->service()->upsert('invoice', $this->inInvoice(['id' => 'd1', 'status_id' => '1'])));
        $this->assertNull($this->service()->upsert('invoice', $this->inInvoice(['id' => 'o1', 'date' => '2026-03-01', 'custom_value1' => ''])));

        $document = $this->service()->upsert('invoice', $this->inInvoice());
        $this->assertSame(OutboundDocument::PENDING, $document->state);
        $this->assertTrue($document->requested);

        $manual = $this->service()->upsert('invoice', $this->inInvoice(['id' => 'm1', 'custom_value1' => '']));
        $this->assertSame(OutboundDocument::DISCOVERED, $manual->state, 'Sans envoi automatique, une facture non marquée ne part pas.');

        $skipped = $this->service()->upsert('invoice', $this->inInvoice(['id' => 's1', 'custom_value1' => 'Ne pas transmettre']));
        $this->assertSame(OutboundDocument::SKIPPED, $skipped->state);
    }

    public function test_prepare_archives_and_validates_without_contacting_the_customer(): void
    {
        $this->fakeHttp();

        $document = $this->readyDocument();

        $this->assertSame(OutboundDocument::READY, $document->state);
        $this->assertSame('B2B', $document->processing_rule);
        $this->assertTrue(Archive::verify($document->file('facturx')));
        $this->assertSame('%PDF-1.7 facturx', Archive::get($document->file('facturx')));
        $this->assertCount(0, $this->sentRequests('POST', '/v1.beta/invoices?'));
        $this->assertContains('sandbox', [$document->directory_status]);
    }

    public function test_validation_failures_block_the_document(): void
    {
        $this->fakeHttp([self::SPDP.'/v1.beta/validation_reports' => Http::response(['data' => [[
            'is_valid' => false, 'subreports' => [['validator' => 'BR-FR', 'checks_count' => 1, 'messages' => [], 'failures' => [['message' => 'BR-FR-12 adresse manquante', 'raw' => 'x']]]],
        ]]])]);

        $document = $this->readyDocument();

        $this->assertSame(OutboundDocument::INVALID, $document->state);
        $this->assertStringContainsString('BR-FR-12', $document->errors[0]);
    }

    public function test_pdf_and_structured_vat_regime_must_match(): void
    {
        $terms = "Exonération de TVA – article 261-4-4°a du CGI\nPénalités de retard : 3 fois le taux légal. Indemnité forfaitaire pour frais de recouvrement : 40 €. Pas d'escompte.";
        $this->fakeHttp([], $invoice = $this->inInvoice(['terms' => $terms]));

        $document = $this->service()->upsert('invoice', $invoice);
        $this->service()->prepare($document);
        $document->refresh();

        $this->assertSame(OutboundDocument::INVALID, $document->state);
        $this->assertStringContainsString('293 B', implode(' ', $document->errors));
        $this->assertStringContainsString('article 261', implode(' ', $document->errors));

        config(['einvoicing.vat.exemption_code' => 'VATEX-EU-132-1I', 'einvoicing.vat.exemption_text' => 'Exonération de TVA - article 261-4-4° a du CGI']);
        $this->service()->prepare($document);

        $this->assertSame(OutboundDocument::READY, $document->refresh()->state, 'Une mention 261-4-4°a cohérente avec le motif structuré passe.');
    }

    public function test_missing_b2b_mentions_block_the_document(): void
    {
        $this->fakeHttp([], $invoice = $this->inInvoice(['terms' => 'TVA non applicable, art. 293 B du CGI']));

        $document = $this->service()->upsert('invoice', $invoice);
        $this->service()->prepare($document);
        $document->refresh();

        $this->assertSame(OutboundDocument::INVALID, $document->state);
        $this->assertCount(3, array_filter($document->errors, fn ($e) => str_contains($e, 'Mention légale absente')));
    }

    public function test_production_default_terms_pass_the_coherence_checks(): void
    {
        $terms = "P&T traditionnels, ni des frais de déplacement hors Île-de- France. \nTVA non applicable, art. 293 B du CGI\nNDA : 11 78 88170 78\n"
            ."Pénalités de retard : trois fois le taux d'intérêt légal.\n"
            ."Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.\n"
            ."Pas d'escompte pour paiement anticipé.";
        $this->fakeHttp([], $invoice = $this->inInvoice(['terms' => $terms]));

        $document = $this->service()->upsert('invoice', $invoice);
        $this->service()->prepare($document);

        $this->assertSame(OutboundDocument::READY, $document->refresh()->state, implode(' | ', $document->errors ?? []));

        $historical = str_replace('TVA non applicable, art. 293 B du CGI', 'Exonération de TVA – article 261-4-4°a du CGI', $terms);
        $this->fakeHttp([], $old = $this->inInvoice(['id' => 'inv2', 'number' => 'FA-OLD', 'terms' => $historical]));
        $oldDocument = $this->service()->upsert('invoice', $old);
        $this->service()->prepare($oldDocument);

        $this->assertSame(OutboundDocument::INVALID, $oldDocument->refresh()->state);
    }

    public function test_retry_from_invoice_ninja_prepares_a_blocked_invoice_again_once(): void
    {
        $invoice = $this->inInvoice(['status_id' => 2, 'custom_value1' => 'Relancer']);
        $this->fakeHttp([], $invoice);
        $document = OutboundDocument::create([
            'entity_type' => 'invoice', 'in_id' => $invoice['id'], 'number' => $invoice['number'], 'issue_date' => $invoice['date'],
            'total' => $invoice['amount'], 'external_id' => OutboundDocument::externalIdFor('invoice', $invoice['id']),
            'state' => OutboundDocument::INVALID, 'requested' => true, 'errors' => ['SIREN du client manquant'],
        ]);

        $this->service()->upsert('invoice', $invoice);

        $this->assertSame(OutboundDocument::PENDING, $document->refresh()->state);
        $put = $this->sentRequests('PUT', '/invoices/'.$invoice['id']);
        $this->assertCount(1, $put);
        $this->assertSame('Transmettre à SuperPDP', $put[0][0]->data()['custom_value1'], 'the former « Relancer » label is still understood');
    }

    public function test_seller_identity_differing_between_pdf_and_xml_is_reported(): void
    {
        $mapped = ['processing_rule' => 'B2C', 'total_with_vat' => 0, 'invoice' => ['vat_break_down' => [], 'seller' => [
            'name' => 'MARTIN DUPONT',
            'postal_address' => ['address_line1' => '1 RUE DE LA PAIX', 'post_code' => '75001'],
        ]]];

        $warnings = $this->service()->coherence([], $mapped, ['name' => 'exemple.fr', 'address_line1' => '1b rue de la Paix', 'post_code' => '75001'])['warnings'];
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('MARTIN DUPONT', $warnings[0]);

        $aligned = ['name' => 'Martin Dupont EI – Demo', 'address_line1' => '1 rue de la Paix', 'post_code' => '75001'];
        $this->assertSame([], $this->service()->coherence([], $mapped, $aligned)['warnings']);
    }

    public function test_credit_on_unpaid_invoice_references_it_through_the_po_number(): void
    {
        $credit = $this->inInvoice(['id' => 'cr1', 'number' => 'AV-001', 'client_id' => 'cli1', 'po_number' => 'FA-TEST-001', 'invoice_id' => '']);
        $this->fakeHttp([
            self::IN.'/credits/cr1/download' => Http::response($this->readablePdf($credit), 200, ['Content-Type' => 'application/pdf']),
            self::IN.'/credits/cr1*' => Http::response(['data' => $credit]),
        ], $this->inInvoice(['client_id' => 'cli1']));

        $document = $this->service()->upsert('credit', $credit);
        $this->service()->prepare($document);
        $document->refresh();

        $this->assertSame(OutboundDocument::READY, $document->state, implode(' | ', $document->errors ?? []));
        $json = json_decode(Archive::get($document->file('en16931')), true);
        $this->assertSame('FA-TEST-001', $json['preceding_invoice_references'][0]['reference']);
        $this->assertEmpty($json['purchase_order_reference'] ?? null, 'Le n° de facture d\'origine ne doit pas devenir une référence de commande (BT-13).');
    }

    public function test_send_is_idempotent(): void
    {
        $this->fakeHttp([self::SPDP.'/v1.beta/invoices?*' => Http::response(['id' => 777, 'external_id' => 'in-invoice-inv1', 'events' => [['status_code' => 'api:uploaded', 'created_at' => '2026-10-01T10:00:00Z']]])]);

        $document = $this->readyDocument();

        $this->assertTrue($this->service()->send($document));
        $this->assertTrue($this->service()->send($document->refresh()));

        $uploads = $this->sentRequests('POST', '/v1.beta/invoices?');
        $this->assertCount(1, $uploads);
        $this->assertStringContainsString('external_id=in-invoice-inv1', $uploads[0][0]->url());
        $this->assertStringContainsString('processing_rule=B2B', $uploads[0][0]->url());
        $this->assertSame(777, $document->refresh()->superpdp_id);
        $this->assertSame(OutboundDocument::SUBMITTED, $document->state);

        $writeBack = $this->sentRequests('PUT', '/invoices/inv1');
        $this->assertNotEmpty($writeBack);
        $this->assertMatchesRegularExpression('#^SuperPDP \#777 — transmise le \d{2}/\d{2}/\d{4} \d{2}:\d{2}$#u', end($writeBack)[0]->data()['custom_value3']);
        $this->assertNotEmpty($this->sentRequests('POST', '/invoices/inv1/upload'), 'La Factur-X est jointe à la facture dans Invoice Ninja.');
        $this->assertStringStartsWith('Factur-X déposée : onglet Documents', end($writeBack)[0]->data()['custom_value4']);

        $notes = $this->sentRequests('POST', '/activities/notes');
        $this->assertCount(1, $notes, 'Une seule note d\'historique pour un seul dépôt.');
        $this->assertSame(['entity' => 'invoices', 'entity_id' => 'inv1'], array_intersect_key($notes[0][0]->data(), ['entity' => 1, 'entity_id' => 1]));
        $this->assertStringContainsString('réf. SuperPDP #777', $notes[0][0]->data()['notes']);

        $this->service()->writeBack($document->refresh());
        $this->service()->writeBack($document->refresh());
        $this->assertCount(1, $this->sentRequests('POST', '/invoices/inv1/upload'), 'Une synchronisation répétée ne joint pas la Factur-X une seconde fois.');
    }

    public function test_an_attachment_already_on_the_invoice_is_not_uploaded_again(): void
    {
        $phase = 'send';
        $invoice = $this->inInvoice();
        $this->fakeHttp([
            self::SPDP.'/v1.beta/invoices?*' => Http::response(['id' => 777, 'external_id' => 'in-invoice-inv1', 'events' => [['status_code' => 'api:uploaded', 'created_at' => '2026-10-01T10:00:00Z']]]),
            self::IN.'/invoices/inv1' => function (Request $request) use (&$phase, $invoice) {
                if ($request->method() === 'PUT') {
                    return Http::response(['data' => array_merge($invoice, $request->data())]);
                }

                $documents = $phase === 'recover' ? [['name' => 'FA-TEST-001-facturx-superpdp-777.pdf']] : [];

                return Http::response(['data' => array_merge($invoice, ['documents' => $documents])]);
            },
        ]);

        $document = $this->readyDocument();
        $this->assertTrue($this->service()->send($document));
        $this->assertCount(1, $this->sentRequests('POST', '/invoices/inv1/upload'));

        $files = $document->refresh()->files;
        unset($files['facturx']['uploaded_to_in']);
        $document->forceFill(['files' => $files])->save();
        $phase = 'recover';

        $this->service()->writeBack($document->refresh());

        $this->assertCount(1, $this->sentRequests('POST', '/invoices/inv1/upload'), 'Une pièce déjà présente n\'est pas déposée une seconde fois.');
        $this->assertNotEmpty($document->refresh()->files['facturx']['uploaded_to_in'] ?? null);
    }

    public function test_a_lost_upload_response_is_recovered_from_the_document_list(): void
    {
        $phase = 'send';
        $lostUploads = 0;
        $invoice = $this->inInvoice();
        $this->fakeHttp([
            self::SPDP.'/v1.beta/invoices?*' => Http::response(['id' => 777, 'external_id' => 'in-invoice-inv1', 'events' => [['status_code' => 'api:uploaded', 'created_at' => '2026-10-01T10:00:00Z']]]),
            self::IN.'/invoices/inv1' => function (Request $request) use (&$phase, &$lostUploads, $invoice) {
                if ($request->method() === 'PUT') {
                    return Http::response(['data' => array_merge($invoice, $request->data())]);
                }

                $documents = $phase === 'lost' && $lostUploads > 0 ? [['name' => 'FA-TEST-001-facturx-superpdp-777.pdf']] : [];

                return Http::response(['data' => array_merge($invoice, ['documents' => $documents])]);
            },
            self::IN.'/invoices/inv1/upload' => function () use (&$phase, &$lostUploads) {
                if ($phase !== 'lost') {
                    return Http::response(['data' => ['id' => 'inv1']]);
                }

                $lostUploads++;

                throw new ConnectionException('réponse perdue');
            },
        ]);

        $document = $this->readyDocument();
        $this->assertTrue($this->service()->send($document));

        $files = $document->refresh()->files;
        unset($files['facturx']['uploaded_to_in']);
        $document->forceFill(['files' => $files])->save();
        $phase = 'lost';

        $this->service()->writeBack($document->refresh());

        $this->assertSame(1, $lostUploads);
        $this->assertNotEmpty($document->refresh()->files['facturx']['uploaded_to_in'] ?? null);
        $this->service()->writeBack($document->refresh());
        $this->assertSame(1, $lostUploads, 'La relance suivante ne dépose plus la pièce.');
    }

    public function test_a_locked_invoice_keeps_its_amounts_and_receives_the_status_as_a_note(): void
    {
        $invoice = $this->inInvoice();
        $puts = 0;
        $this->fakeHttp([
            self::SPDP.'/v1.beta/invoices?*' => Http::response(['id' => 777, 'external_id' => 'in-invoice-inv1', 'events' => [['status_code' => 'api:uploaded', 'created_at' => '2026-10-01T10:00:00Z']]]),
            self::IN.'/invoices/inv1*' => function (Request $request) use (&$puts, $invoice) {
                if (str_contains($request->url(), '/download')) {
                    return Http::response($this->readablePdf($invoice), 200, ['Content-Type' => 'application/pdf']);
                }

                if (str_contains($request->url(), '/upload')) {
                    return Http::response(['data' => ['id' => 'inv1']]);
                }

                if ($request->method() === 'PUT') {
                    $puts++;
                    $this->assertSame(['custom_value2', 'custom_value3', 'custom_value4'], array_keys($request->data()));

                    return Http::response(['message' => '', 'errors' => ['number' => 'Cette facture est verrouillée et ne peut pas être modifiée']], 422);
                }

                return Http::response(['data' => $invoice]);
            },
        ]);

        $document = $this->readyDocument();
        $this->assertTrue($this->service()->send($document));

        foreach ($this->sentRequests('PUT', '/invoices/inv1') as $sent) {
            $this->assertSame([], array_diff(array_keys($sent[0]->data()), ['custom_value1', 'custom_value2', 'custom_value3', 'custom_value4']));
        }

        $locked = fn () => array_values(array_filter(
            array_map(fn ($r) => $r[0]->data()['notes'], $this->sentRequests('POST', '/activities/notes')),
            fn ($n) => str_contains($n, 'verrouillée'),
        ));
        $this->assertNotEmpty($locked());
        $this->assertStringContainsString('sans modification des montants', $locked()[0]);
        $before = count($locked());
        $putsBefore = $puts;
        $this->service()->writeBack($document->refresh());
        $this->assertSame($before, count($locked()));
        $this->assertSame($putsBefore, $puts, 'Le verrouillage ne déclenche pas une nouvelle écriture à chaque relecture du jour.');
        $this->assertNotEmpty($this->sentRequests('POST', '/invoices/inv1/upload'));
    }

    public function test_network_failure_is_retried_later_and_an_interrupted_upload_is_found_again(): void
    {
        $calls = 0;
        $this->fakeHttp([
            self::SPDP.'/v1.beta/invoices?*' => function (Request $request) use (&$calls) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => [['id' => 778, 'external_id' => 'in-invoice-inv1', 'events' => []]], 'count' => 1, 'has_after' => false, 'has_before' => false]);
                }

                $calls++;
                throw new ConnectionException('timeout');
            },
        ]);

        $document = $this->readyDocument();
        $this->assertFalse($this->service()->send($document));

        $document->refresh();
        $this->assertSame(OutboundDocument::READY, $document->state);
        $this->assertSame(1, $document->attempts);
        $this->assertNotNull($document->next_attempt_at);

        // The upload actually reached SUPER PDP: the next attempt finds it instead of uploading twice.
        $this->assertTrue($this->service()->send($document));
        $this->assertSame(1, $calls);
        $this->assertSame(778, $document->refresh()->superpdp_id);
    }

    public function test_already_exists_response_is_recovered_without_a_second_deposit(): void
    {
        $posts = 0;
        $this->fakeHttp([
            self::SPDP.'/v1.beta/invoices?*' => function (Request $request) use (&$posts) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => [['id' => 779, 'external_id' => 'in-invoice-inv1', 'events' => []]], 'count' => 1, 'has_after' => false, 'has_before' => false]);
                }

                $posts++;

                return Http::response(['message' => 'La facture est déjà existante (id 779)'], 400);
            },
        ]);

        $document = $this->readyDocument();
        $this->assertTrue($this->service()->send($document));
        $this->assertSame(1, $posts);
        $this->assertSame(779, $document->refresh()->superpdp_id);
        $this->assertSame(OutboundDocument::SUBMITTED, $document->state);
    }

    public function test_send_is_refused_while_the_connector_is_disabled(): void
    {
        $this->fakeHttp();
        $document = $this->readyDocument();
        config(['einvoicing.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->service()->send($document);
    }

    public function test_reception_only_mode_never_uploads_a_sales_invoice(): void
    {
        $this->fakeHttp();
        $document = $this->readyDocument();
        $document->forceFill(['requested' => true])->save();
        config(['einvoicing.send_enabled' => false]);

        $this->assertFalse($this->service()->maySend($document));
        $this->service()->processQueue();
        $this->assertSame(OutboundDocument::READY, $document->refresh()->state);
        $this->assertNull($document->superpdp_id);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/v1.beta/invoices?'));

        $this->expectException(RuntimeException::class);
        $this->service()->send($document);
    }

    public function test_rejection_status_is_shown_with_its_reason(): void
    {
        $this->fakeHttp([
            self::SPDP.'/v1.beta/invoices?*' => Http::response(['id' => 900, 'events' => []]),
            self::SPDP.'/v1.beta/invoice_events*' => Http::response(['data' => [[
                'id' => 5, 'invoice_id' => 900, 'status_code' => 'fr:213', 'status_text' => 'Rejetée', 'created_at' => '2026-10-02T08:00:00Z',
                'details' => [['reason' => 'DOUBLON', 'notes' => [['subject' => 'AAI', 'contents' => [['content' => 'Facture déjà reçue']]]]]],
            ]], 'has_after' => false]),
        ]);

        $document = $this->readyDocument();
        $this->service()->send($document);
        app(EventSync::class)->run();
        app(EventSync::class)->run();

        $document->refresh();
        $this->assertSame(OutboundDocument::REJECTED, $document->state);
        $this->assertStringContainsString('DOUBLON', $document->rejection_reason);
        $this->assertStringContainsString('Facture déjà reçue', $document->rejection_reason);
        $this->assertSame(1, $document->events()->count());

        $lastPut = collect($this->sentRequests('PUT', '/invoices/inv1'))->last()[0]->data();
        $this->assertStringContainsString('fr:213', $lastPut['custom_value2']);
        $this->assertStringContainsString('DOUBLON', $lastPut['custom_value4']);

        $alerts = ExchangeLog::query()->where('channel', 'notify')->where('action', 'rejected')->pluck('message');
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('DOUBLON', $alerts[0]);
    }

    public function test_customer_missing_from_directory_is_blocked_in_production(): void
    {
        config(['einvoicing.superpdp.environment' => 'production']);
        app()->forgetInstance(SuperPdpClient::class);
        $this->fakeHttp([
            self::SPDP.'/v1.beta/companies/me' => Http::response(['id' => 1, 'formal_name' => 'Vendeur Test', 'env' => 'production']),
            self::SPDP.'/v1.beta/french_directory/entries*' => Http::response(['data' => []]),
        ]);

        $document = $this->readyDocument();

        $this->assertSame(OutboundDocument::INVALID, $document->state);
        $this->assertStringContainsString('annuaire', $document->errors[0]);
        $this->assertCount(0, $this->sentRequests('POST', '/invoices/convert'));
    }

    public function test_sandbox_invoices_are_issued_by_the_test_company(): void
    {
        $this->fakeHttp([self::SPDP.'/v1.beta/companies/me' => Http::response(['id' => 2, 'formal_name' => 'Burger Queen', 'number' => '315143296', 'env' => 'sandbox'])]);

        $document = $this->service()->upsert('invoice', $this->inInvoice());
        $result = $this->service()->prepare($document, dryRun: true);

        $this->assertSame('315143296', $result['mapped']['invoice']['seller']['legal_registration_identifier']['value']);
        $this->assertSame('Burger Queen', $result['mapped']['invoice']['seller']['name']);
    }

    public function test_production_refuses_a_seller_siren_different_from_the_account(): void
    {
        config(['einvoicing.superpdp.environment' => 'production']);
        app()->forgetInstance(SuperPdpClient::class);
        $this->fakeHttp([self::SPDP.'/v1.beta/companies/me' => Http::response(['id' => 1, 'formal_name' => 'Autre', 'number' => '999999999', 'env' => 'production'])]);

        $document = $this->readyDocument();

        $this->assertSame(OutboundDocument::INVALID, $document->state);
        $this->assertStringContainsString('ne correspond pas au compte', $document->errors[0]);
    }

    public function test_payment_is_reported_once(): void
    {
        $paid = $this->inInvoice(['status_id' => '4', 'balance' => 0, 'paid_to_date' => 1000]);
        $this->fakeHttp([
            self::SPDP.'/v1.beta/invoices?*' => Http::response(['id' => 901, 'events' => []]),
            self::SPDP.'/v1.beta/invoices/901' => Http::response(['id' => 901, 'events' => [['status_code' => 'api:uploaded']]]),
            self::SPDP.'/v1.beta/invoice_events' => Http::response(['id' => 1, 'status_code' => 'fr:212']),
        ], $paid);

        $document = $this->service()->upsert('invoice', $this->inInvoice());
        $this->service()->prepare($document);
        $this->service()->send($document->refresh());

        $this->assertTrue($this->service()->reportPayment($document->refresh(), force: true));
        $this->assertFalse($this->service()->reportPayment($document->refresh(), force: true));

        $events = $this->sentRequests('POST', '/v1.beta/invoice_events');
        $this->assertCount(1, $events);
        $this->assertSame(['invoice_id' => 901, 'status_code' => 'fr:212'], $events[0][0]->data());
    }
}
