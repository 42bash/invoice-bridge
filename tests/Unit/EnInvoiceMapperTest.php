<?php

namespace Tests\Unit;

use App\Services\Mapping\EnInvoiceMapper;
use App\Services\Mapping\MappingException;
use Tests\Support\Fixtures;
use Tests\TestCase;

class EnInvoiceMapperTest extends TestCase
{
    use Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureConnector();
    }

    private function map(array $invoice, array $options = []): array
    {
        return (new EnInvoiceMapper)->map($invoice, config('einvoicing.seller'), fn ($id) => $id === '250' ? 'FR' : null, $options + ['kind' => 'invoice', 'currency_code' => 'EUR']);
    }

    public function test_b2b_invoice_under_vat_franchise(): void
    {
        $result = $this->map($this->inInvoice());
        $invoice = $result['invoice'];

        $this->assertSame('B2B', $result['processing_rule']);
        $this->assertSame(380, $result['type_code']);
        $this->assertSame(['scheme' => '0002', 'value' => '123456782'], $invoice['seller']['legal_registration_identifier']);
        $this->assertSame(['scheme' => '0002', 'value' => '987654321'], $invoice['buyer']['legal_registration_identifier']);
        $this->assertSame('urn:cen.eu:en16931:2017', $invoice['process_control']['specification_identifier']);
        $this->assertSame('S1', $invoice['process_control']['business_process_type']);

        $this->assertCount(1, $invoice['vat_break_down']);
        $this->assertSame('E', $invoice['vat_break_down'][0]['vat_category_code']);
        $this->assertSame('VATEX-FR-FRANCHISE', $invoice['vat_break_down'][0]['vat_exemption_reason_code']);
        $this->assertSame('1000.00', $invoice['totals']['total_with_vat']);
        $this->assertSame('1000.00', $invoice['totals']['amount_due_for_payment']);
        $this->assertSame('2026-10-31', $invoice['payment_due_date']);

        $subjects = array_column($invoice['notes'], 'subject_code');
        foreach (['PMT', 'PMD', 'AAB'] as $required) {
            $this->assertContains($required, $subjects, "BR-FR-05 : note {$required} manquante");
        }
    }

    public function test_sandbox_never_addresses_the_real_customer(): void
    {
        $result = $this->map($this->inInvoice(), ['sandbox_buyer_address' => '315143296_TEST']);

        $this->assertSame('0225:315143296_TEST', $result['buyer_electronic_address']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_sandbox_without_test_address_is_refused(): void
    {
        $this->expectException(MappingException::class);
        $this->map($this->inInvoice(), ['sandbox_buyer_address' => '']);
    }

    public function test_invoice_paid_at_issue_uses_s2_with_payment_date_as_due_date(): void
    {
        $invoice = $this->inInvoice([
            'status_id' => '4', 'balance' => 0, 'paid_to_date' => 1000, 'due_date' => '',
            'payments' => [['date' => '2026-10-01', 'is_deleted' => false, 'paymentables' => [['invoice_id' => 'inv1', 'amount' => 1000, 'refunded' => 0]]]],
        ]);

        $out = $this->map($invoice)['invoice'];

        $this->assertSame('S2', $out['process_control']['business_process_type']);
        $this->assertSame('2026-10-01', $out['payment_due_date']);
        $this->assertSame('1000.00', $out['totals']['paid_amount']);
        $this->assertSame('0.00', $out['totals']['amount_due_for_payment']);
    }

    public function test_invoice_paid_after_issue_stays_s1_without_paid_amount(): void
    {
        $invoice = $this->inInvoice([
            'status_id' => '4', 'balance' => 0, 'paid_to_date' => 1000,
            'payments' => [['date' => '2026-10-20', 'is_deleted' => false, 'paymentables' => [['invoice_id' => 'inv1', 'amount' => 1000, 'refunded' => 0]]]],
        ]);

        $out = $this->map($invoice)['invoice'];

        $this->assertSame('S1', $out['process_control']['business_process_type']);
        $this->assertArrayNotHasKey('paid_amount', $out['totals']);
        $this->assertSame('1000.00', $out['totals']['amount_due_for_payment']);
    }

    public function test_credit_note_must_reference_the_invoice(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('BR-FR-CO-05');
        $this->map($this->inInvoice(['number' => 'AV-1']), ['kind' => 'credit']);
    }

    public function test_credit_note_with_preceding_invoice(): void
    {
        $result = $this->map($this->inInvoice(['number' => 'AV-1']), [
            'kind' => 'credit',
            'preceding' => [['reference' => 'FA-TEST-001', 'issue_date' => '2026-10-01', 'type_code' => 380]],
        ]);

        $this->assertSame(381, $result['type_code']);
        $this->assertSame(['reference' => 'FA-TEST-001', 'issue_date' => '2026-10-01'], $result['invoice']['preceding_invoice_references'][0]);
    }

    public function test_deposit_invoice_type(): void
    {
        $this->assertSame(386, $this->map($this->inInvoice(), ['deposit' => true])['type_code']);
    }

    public function test_individual_customer_is_b2c_with_bar_note(): void
    {
        $invoice = $this->inInvoice(['client' => $this->client(['classification' => 'individual', 'id_number' => ''])]);
        $result = $this->map($invoice);

        $this->assertSame('B2C', $result['processing_rule']);
        $this->assertSame('EM:compta@client.test', $result['buyer_electronic_address']);
        $this->assertContains(['note' => 'B2C', 'subject_code' => 'BAR'], $result['invoice']['notes']);
    }

    public function test_b2b_customer_without_siren_is_refused(): void
    {
        $this->expectException(MappingException::class);
        $this->map($this->inInvoice(['client' => $this->client(['id_number' => ''])]));
    }

    public function test_vat_rate_under_franchise_is_refused(): void
    {
        $invoice = $this->inInvoice(['amount' => 1200]);
        $invoice['line_items'][0]['tax_rate1'] = 20;
        $invoice['line_items'][0]['tax_name1'] = 'TVA';

        $this->expectException(MappingException::class);
        $this->map($invoice);
    }

    public function test_vat_invoice_when_not_under_franchise(): void
    {
        config(['einvoicing.vat.franchise' => false]);
        $invoice = $this->inInvoice(['amount' => 1200]);
        $invoice['line_items'][0]['tax_rate1'] = 20;

        $out = $this->map($invoice)['invoice'];

        $this->assertSame('S', $out['vat_break_down'][0]['vat_category_code']);
        $this->assertSame('200.00', $out['vat_break_down'][0]['vat_category_tax_amount']);
        $this->assertSame('1200.00', $out['totals']['total_with_vat']);
    }

    public function test_totals_mismatch_is_refused(): void
    {
        $this->expectException(MappingException::class);
        $this->map($this->inInvoice(['amount' => 1500]));
    }

    public function test_draft_without_number_is_refused(): void
    {
        $this->expectException(MappingException::class);
        $this->map($this->inInvoice(['number' => '']));
    }

    public function test_public_customer_is_refused(): void
    {
        $this->expectException(MappingException::class);
        $this->map($this->inInvoice(['client' => $this->client(['classification' => 'government'])]));
    }

    public function test_foreign_customer_is_b2bint(): void
    {
        $result = (new EnInvoiceMapper)->map(
            $this->inInvoice(['client' => $this->client(['country_id' => '56', 'id_number' => '', 'vat_number' => 'BE0123456789'])]),
            config('einvoicing.seller'),
            fn ($id) => ['250' => 'FR', '56' => 'BE'][$id] ?? null,
            ['kind' => 'invoice', 'currency_code' => 'EUR'],
        );

        $this->assertSame('B2BInt', $result['processing_rule']);
    }

    public function test_foreign_currency_gives_the_vat_total_in_euro(): void
    {
        $usd = $this->map($this->inInvoice(['exchange_rate' => 1.08]), ['currency_code' => 'USD'])['invoice'];

        $this->assertSame('USD', $usd['currency_code']);
        $this->assertSame('EUR', $usd['vat_accounting_currency_code']);
        $this->assertSame(['value' => '0.00', 'currency_code' => 'EUR'], $usd['totals']['total_vat_amount_accounting_currency']);

        $eur = $this->map($this->inInvoice())['invoice'];
        $this->assertArrayNotHasKey('vat_accounting_currency_code', $eur);
    }
}
