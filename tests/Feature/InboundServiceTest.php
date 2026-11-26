<?php

namespace Tests\Feature;

use App\Models\InboundDocument;
use App\Services\InboundService;
use App\Support\Archive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;
use Tests\TestCase;

class InboundServiceTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;

    private const ORIGINAL_XML = '<?xml version="1.0"?><rsm:CrossIndustryInvoice>original</rsm:CrossIndustryInvoice>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureConnector();
    }

    private function fakeReception(array $received, array $vendors = [], array $expenses = [], array $extra = []): void
    {
        $fakes = [];

        foreach ($received as $id => $en) {
            $fakes[self::SPDP."/v1.beta/invoices/{$id}?format=original"] = Http::response(self::ORIGINAL_XML, 200, ['Content-Type' => 'application/xml']);
            $fakes[self::SPDP."/v1.beta/invoices/{$id}?format=factur-x"] = Http::response('%PDF-1.7 lisible', 200, ['Content-Type' => 'application/pdf']);
            $fakes[self::SPDP."/v1.beta/invoices/{$id}"] = Http::response(['id' => $id, 'direction' => 'in', 'created_at' => '2026-10-05T09:00:00Z', 'en_invoice' => $en, 'events' => [['status_code' => 'api:received', 'created_at' => '2026-10-05T09:00:00Z']]]);
        }

        $this->fakeHttp($extra + $fakes + [
            self::SPDP.'/v1.beta/invoices?*' => Http::response(['data' => array_map(fn ($id) => ['id' => $id, 'direction' => 'in'], array_keys($received)), 'count' => count($received), 'has_after' => false, 'has_before' => false]),
            self::IN.'/vendors*' => function (Request $request) use (&$vendors) {
                if ($request->method() === 'POST') {
                    $vendor = ['id' => 'ven'.(count($vendors) + 1)] + $request->data();
                    $vendors[] = $vendor;

                    return Http::response(['data' => $vendor]);
                }

                return Http::response(['data' => $vendors, 'meta' => ['pagination' => ['total_pages' => 1]]]);
            },
            self::IN.'/expenses/*/upload' => Http::response(['data' => []]),
            self::IN.'/expenses*' => function (Request $request) use (&$expenses) {
                if ($request->method() === 'POST') {
                    $expense = ['id' => 'exp'.(count($expenses) + 1), 'is_deleted' => false] + $request->data();
                    $expenses[] = $expense;

                    return Http::response(['data' => $expense]);
                }

                if ($request->method() === 'PUT') {
                    return Http::response(['data' => $request->data()]);
                }

                return Http::response(['data' => $expenses, 'meta' => ['pagination' => ['total_pages' => 1]]]);
            },
        ]);
    }

    public function test_received_invoice_is_archived_untouched_and_imported_as_expense(): void
    {
        $this->fakeReception([501 => $this->enInvoice()]);
        $inbound = app(InboundService::class);

        $this->assertSame(1, $inbound->poll());
        $this->assertSame(1, $inbound->importPending());

        $document = InboundDocument::query()->firstOrFail();
        $this->assertSame(InboundDocument::IMPORTED, $document->state);
        $this->assertSame('552100554', $document->seller_siren);
        $this->assertSame(self::ORIGINAL_XML, Archive::get($document->file('original')), 'Le fichier original est conservé à l\'identique.');
        $this->assertSame(hash('sha256', self::ORIGINAL_XML), $document->file('original')['sha256']);
        $this->assertNotNull($document->file('readable'));

        $vendor = $this->sentRequests('POST', '#/vendors(\?|$)#')[0][0]->data();
        $this->assertSame('552100554', $vendor['id_number']);
        $this->assertSame('FR40552100554', $vendor['vat_number']);

        $expense = $this->sentRequests('POST', '#/expenses(\?|$)#')[0][0]->data();
        $this->assertEquals(120.0, $expense['amount']);
        $this->assertTrue($expense['uses_inclusive_taxes']);
        $this->assertEquals(20.0, $expense['tax_amount1']);
        $this->assertArrayNotHasKey('transaction_reference', $expense, 'Invoice Ninja afficherait la dépense comme payée.');
        $this->assertStringContainsString(' fournisseur F-2026-42 du ', $expense['public_notes']);
        $this->assertSame('SuperPDP #501', $expense['custom_value1']);
        $this->assertCount(2, $this->sentRequests('POST', '/expenses/exp1/upload'));
    }

    public function test_polling_twice_does_not_duplicate(): void
    {
        $this->fakeReception([501 => $this->enInvoice()]);
        $inbound = app(InboundService::class);

        $inbound->poll();
        $inbound->importPending();
        $inbound->poll();
        $inbound->importPending();

        $this->assertSame(1, InboundDocument::query()->count());
        $this->assertCount(1, $this->sentRequests('POST', '#/expenses(\?|$)#'));
    }

    public function test_same_invoice_received_twice_is_flagged_as_duplicate(): void
    {
        $this->fakeReception([501 => $this->enInvoice(), 502 => $this->enInvoice()]);
        $inbound = app(InboundService::class);

        $inbound->poll();
        $inbound->importPending();

        $duplicate = InboundDocument::query()->where('superpdp_id', 502)->firstOrFail();
        $this->assertSame(InboundDocument::DUPLICATE, $duplicate->state);
        $this->assertNotNull($duplicate->file('original'), 'Un doublon reste archivé.');
        $this->assertCount(1, $this->sentRequests('POST', '#/expenses(\?|$)#'));
    }

    public function test_existing_vendor_is_reused_and_existing_expense_is_linked_not_duplicated(): void
    {
        $this->fakeReception(
            [501 => $this->enInvoice()],
            vendors: [['id' => 'v9', 'name' => 'Fournisseur Test', 'id_number' => '55210055400017', 'vat_number' => '']],
            expenses: [['id' => 'e9', 'vendor_id' => 'v9', 'transaction_reference' => 'F-2026-42', 'is_deleted' => false, 'custom_value1' => '']],
        );
        $inbound = app(InboundService::class);

        $inbound->poll();
        $inbound->importPending();

        $document = InboundDocument::query()->firstOrFail();
        $this->assertSame('v9', $document->in_vendor_id);
        $this->assertSame('e9', $document->in_expense_id);
        $this->assertCount(0, $this->sentRequests('POST', '#/vendors(\?|$)#'));
        $this->assertCount(0, $this->sentRequests('POST', '#/expenses(\?|$)#'));
    }

    public function test_existing_expense_is_found_by_the_supplier_number_in_its_notes(): void
    {
        $this->fakeReception(
            [501 => $this->enInvoice()],
            vendors: [['id' => 'v9', 'name' => 'Fournisseur Test', 'id_number' => '55210055400017', 'vat_number' => '']],
            expenses: [['id' => 'e9', 'vendor_id' => 'v9', 'public_notes' => 'Facture fournisseur F-2026-42 du 01/10/2026 reçue via SUPER PDP.', 'is_deleted' => false, 'custom_value1' => '']],
        );
        $inbound = app(InboundService::class);

        $inbound->poll();
        $inbound->importPending();

        $this->assertSame('e9', InboundDocument::query()->firstOrFail()->in_expense_id);
        $this->assertCount(0, $this->sentRequests('POST', '#/expenses(\?|$)#'));
    }

    public function test_ambiguous_vendor_needs_review(): void
    {
        $this->fakeReception([501 => $this->enInvoice()], vendors: [
            ['id' => 'v1', 'name' => 'Fournisseur A', 'id_number' => '552100554', 'vat_number' => ''],
            ['id' => 'v2', 'name' => 'Fournisseur B', 'id_number' => '552100554', 'vat_number' => ''],
        ]);
        $inbound = app(InboundService::class);

        $inbound->poll();
        $inbound->importPending();

        $this->assertSame(InboundDocument::NEEDS_REVIEW, InboundDocument::query()->firstOrFail()->state);
        $this->assertCount(0, $this->sentRequests('POST', '#/expenses(\?|$)#'));
    }

    public function test_received_credit_note_becomes_a_negative_expense(): void
    {
        $this->fakeReception([503 => $this->enInvoice(['number' => 'AV-7', 'type_code' => 381])]);
        $inbound = app(InboundService::class);

        $inbound->poll();
        $inbound->importPending();

        $expense = $this->sentRequests('POST', '#/expenses(\?|$)#')[0][0]->data();
        $this->assertEquals(-120.0, $expense['amount']);
        $this->assertStringStartsWith('Avoir', $expense['public_notes']);
    }

    public function test_nothing_is_imported_when_automatic_import_is_off(): void
    {
        config(['einvoicing.auto_receive' => false]);
        $this->fakeReception([501 => $this->enInvoice()]);
        $inbound = app(InboundService::class);

        $inbound->poll();
        $this->assertSame(0, $inbound->importPending());
        $this->assertSame(InboundDocument::RECEIVED, InboundDocument::query()->firstOrFail()->state);
        $this->assertCount(0, $this->sentRequests('POST', '#/expenses(\?|$)#'));
    }

    public function test_refusal_requires_a_reason_code(): void
    {
        $this->fakeReception([501 => $this->enInvoice()]);
        $inbound = app(InboundService::class);
        $inbound->poll();

        $this->expectException(\InvalidArgumentException::class);
        $inbound->sendBuyerStatus(InboundDocument::query()->firstOrFail(), 'fr:210');
    }
}
