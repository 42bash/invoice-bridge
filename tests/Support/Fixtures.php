<?php

namespace Tests\Support;

use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\InvoiceNinja\Statics;
use App\Services\SuperPdp\SuperPdpClient;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/** Fictitious data only: no real customer, supplier or credential. */
trait Fixtures
{
    protected const IN = 'https://in.test/api/v1';

    protected const SPDP = 'https://spdp.test';

    protected function configureConnector(array $overrides = []): void
    {
        Sleep::fake();
        Storage::fake('archive');

        config(array_merge([
            'einvoicing.enabled' => true,
            'einvoicing.send_enabled' => true,
            'einvoicing.auto_send' => false,
            'einvoicing.auto_receive' => true,
            'einvoicing.report_payments' => false,
            'einvoicing.auto_create_vendors' => true,
            'einvoicing.send_from_date' => '2026-09-01',
            'einvoicing.invoice_ninja.url' => 'https://in.test',
            'einvoicing.invoice_ninja.token' => 'in-token-test',
            'einvoicing.invoice_ninja.write_back' => true,
            'einvoicing.invoice_ninja.upload_documents' => true,
            'einvoicing.invoice_ninja.expense_category' => null,
            'einvoicing.superpdp.api_url' => self::SPDP,
            'einvoicing.superpdp.client_id' => 'client-test',
            'einvoicing.superpdp.client_secret' => 'secret-test',
            'einvoicing.superpdp.environment' => 'sandbox',
            'einvoicing.superpdp.sandbox_buyer_address' => '315143296_TEST',
            'einvoicing.seller' => [
                'name' => 'Vendeur Test',
                'siren' => '123456782',
                'electronic_address' => '0225:123456782',
                'address_line1' => '1 rue du Test',
                'post_code' => '75001',
                'city' => 'Paris',
                'country' => 'FR',
                'email' => 'vendeur@example.test',
                'iban' => 'FR7630006000011234567890189',
            ],
            'einvoicing.vat.franchise' => true,
            'einvoicing.vat.exemption_code' => 'VATEX-FR-FRANCHISE',
            'einvoicing.vat.exemption_text' => 'TVA non applicable, art. 293 B du CGI',
        ], $overrides));

        foreach ([InvoiceNinjaClient::class, SuperPdpClient::class, Statics::class] as $class) {
            app()->forgetInstance($class);
        }
    }

    protected function client(array $overrides = []): array
    {
        return array_merge([
            'id' => 'cli1',
            'name' => 'Client Test SAS',
            'display_name' => 'Client Test SAS',
            'id_number' => '987654321',
            'vat_number' => '',
            'routing_id' => '',
            'classification' => 'business',
            'address1' => '2 avenue du Client',
            'city' => 'Lyon',
            'postal_code' => '69001',
            'country_id' => '250',
            'is_tax_exempt' => false,
            'settings' => ['currency_id' => '3'],
            'contacts' => [['email' => 'compta@client.test', 'is_primary' => true]],
        ], $overrides);
    }

    protected function inInvoice(array $overrides = []): array
    {
        return array_merge([
            'id' => 'inv1',
            'number' => 'FA-TEST-001',
            'date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'status_id' => '2',
            'is_deleted' => false,
            'amount' => 1000,
            'balance' => 1000,
            'paid_to_date' => 0,
            'discount' => 0,
            'is_amount_discount' => true,
            'uses_inclusive_taxes' => false,
            'tax_rate1' => 0, 'tax_rate2' => 0, 'tax_rate3' => 0,
            'custom_value1' => 'Transmettre à SuperPDP',
            'custom_value2' => '', 'custom_value3' => '', 'custom_value4' => '',
            'public_notes' => '',
            'terms' => 'TVA non applicable, art. 293 B du CGI. Pénalités de retard : 3 fois le taux légal. Indemnité forfaitaire pour frais de recouvrement : 40 €. Pas d\'escompte.',
            'footer' => '',
            'line_items' => [[
                'product_key' => 'Développement',
                'notes' => 'Prestation de développement',
                'cost' => 500,
                'quantity' => 2,
                'line_total' => 1000,
                'tax_rate1' => 0, 'tax_rate2' => 0, 'tax_rate3' => 0,
                'tax_id' => '1',
                'type_id' => '1',
                'date' => '',
            ]],
            'client' => $this->client(),
            'payments' => [],
            'updated_at' => 1790000000,
        ], $overrides);
    }

    protected function enInvoice(array $overrides = []): array
    {
        return array_replace_recursive([
            'number' => 'F-2026-42',
            'issue_date' => '2026-10-05',
            'payment_due_date' => '2026-11-05',
            'type_code' => 380,
            'currency_code' => 'EUR',
            'seller' => [
                'name' => 'Fournisseur Test SARL',
                'legal_registration_identifier' => ['scheme' => '0002', 'value' => '552100554'],
                'vat_identifier' => 'FR40552100554',
                'electronic_address' => ['scheme' => '0225', 'value' => '552100554'],
                'postal_address' => ['address_line1' => '3 rue Fournisseur', 'city' => 'Nantes', 'post_code' => '44000', 'country_code' => 'FR'],
                'contact' => ['email_address' => 'factures@fournisseur.test'],
            ],
            'buyer' => ['name' => 'Vendeur Test', 'legal_registration_identifier' => ['scheme' => '0002', 'value' => '123456782']],
            'vat_break_down' => [['vat_category_code' => 'S', 'vat_category_rate' => '20.00', 'vat_category_taxable_amount' => '100.00', 'vat_category_tax_amount' => '20.00']],
            'totals' => ['total_without_vat' => '100.00', 'total_vat_amount' => ['value' => '20.00', 'currency_code' => 'EUR'], 'total_with_vat' => '120.00', 'amount_due_for_payment' => '120.00'],
            'lines' => [['identifier' => '1', 'invoiced_quantity' => '1', 'net_amount' => '100.00', 'item_information' => ['name' => 'Hébergement'], 'vat_information' => ['invoiced_item_vat_category_code' => 'S', 'invoiced_item_vat_rate' => '20.00']]],
        ], $overrides);
    }

    /** Default HTTP world: SUPER PDP sandbox account + Invoice Ninja with one invoice. Extra fakes take precedence. */
    protected function fakeHttp(array $extra = [], ?array $invoice = null): void
    {
        $invoice ??= $this->inInvoice();

        Http::preventStrayRequests();
        Http::fake($extra + [
            self::SPDP.'/oauth2/token' => Http::response(['access_token' => 'at-1', 'expires_in' => 1800, 'token_type' => 'bearer']),
            self::SPDP.'/v1.beta/companies/me' => Http::response(['id' => 1, 'formal_name' => 'Vendeur Test', 'number' => '123456782', 'env' => 'sandbox']),
            self::SPDP.'/v1.beta/directory_entries' => Http::response(['data' => []]),
            self::SPDP.'/v1.beta/french_directory/entries*' => Http::response(['data' => [['identifier' => '0225:987654321', 'is_active' => true, 'company' => ['number' => '987654321', 'formal_name' => 'Client Test SAS', 'address' => '', 'postcode' => '', 'city' => '', 'country' => 'FR']]]]),
            self::SPDP.'/v1.beta/invoices/convert*' => Http::response('%PDF-1.7 facturx', 200, ['Content-Type' => 'application/pdf']),
            self::SPDP.'/v1.beta/validation_reports' => Http::response(['data' => [['is_valid' => true, 'file_name' => 'x.pdf', 'file_size' => 10, 'duration' => 1, 'format' => 'factur-x', 'conformance_level' => 'urn:cen.eu:en16931:2017', 'subreports' => []]]]),
            self::IN.'/activities/notes' => Http::response(['data' => ['id' => 'note1']]),
            self::IN.'/statics' => Http::response(['countries' => [['id' => 250, 'iso_3166_2' => 'FR']], 'currencies' => [['id' => 3, 'code' => 'EUR']]]),
            self::IN.'/companies' => Http::response(['data' => [['settings' => ['name' => 'Vendeur Test'], 'custom_fields' => []]]]),
            self::IN.'/invoices/'.$invoice['id'].'/download' => Http::response($this->readablePdf($invoice), 200, ['Content-Type' => 'application/pdf']),
            self::IN.'/invoices/'.$invoice['id'].'/upload' => Http::response(['data' => ['id' => $invoice['id']]]),
            self::IN.'/invoices/'.$invoice['id'].'*' => function (Request $request) use ($invoice) {
                return Http::response(['data' => $request->method() === 'PUT' ? array_merge($invoice, $request->data()) : $invoice]);
            },
            self::IN.'/invoices*' => Http::response(['data' => [$invoice], 'meta' => ['pagination' => ['total_pages' => 1]]]),
            self::IN.'/credits*' => Http::response(['data' => [], 'meta' => ['pagination' => ['total_pages' => 1]]]),
        ]);
    }

    /**
     * A real one-page PDF. An unreadable stub would now block the transmission,
     * so the fixture must carry the same number, dates, seller and amounts as
     * the invoice the test is preparing.
     */
    protected function readablePdf(array $invoice): string
    {
        $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ');
        $date = fn ($value) => filled($value) ? Carbon::parse($value)->format('d/m/Y') : '';
        $seller = (string) (config('einvoicing.seller.name') ?: 'Vendeur Test');
        $siren = (string) config('einvoicing.seller.siren');
        $lines = array_filter([
            $seller,
            $siren !== '' ? 'SIREN : '.$siren : null,
            'Facture n° '.$invoice['number'],
            trim('Date : '.$date($invoice['date'] ?? null).'   Échéance : '.$date($invoice['due_date'] ?? null)),
            (string) ($invoice['client']['name'] ?? ''),
        ]);

        foreach ($invoice['line_items'] ?? [] as $item) {
            $net = $item['line_total'] ?? ((float) ($item['cost'] ?? 0) * (float) ($item['quantity'] ?? 0));
            $lines[] = trim(($item['product_key'] ?? 'Prestation').'  '.$money($net).' €');
        }

        $lines[] = 'Total : '.$money($invoice['amount'] ?? 0).' €';

        foreach (preg_split("/\r\n|\n|\r/", (string) ($invoice['terms'] ?? '')) as $term) {
            if (trim($term) !== '') {
                $lines[] = trim($term);
            }
        }

        return $this->pdfDocument($lines);
    }

    /** @param  list<string>  $lines */
    private function pdfDocument(array $lines): string
    {
        $stream = "BT /F1 10 Tf 40 800 Td 14 TL\n";

        foreach ($lines as $line) {
            $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $line));
            $stream .= "({$text}) Tj T*\n";
        }

        $stream .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    /** $url is a substring, or a regex when it starts with "#". */
    protected function sentRequests(string $method, string $url): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method
            && (str_starts_with($url, '#') ? preg_match($url, $r->url()) === 1 : str_contains($r->url(), $url)))
            ->values()
            ->all();
    }
}
