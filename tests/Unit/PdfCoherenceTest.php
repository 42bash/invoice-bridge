<?php

namespace Tests\Unit;

use App\Services\PdfCoherence;
use PHPUnit\Framework\TestCase;

class PdfCoherenceTest extends TestCase
{
    /** Minimal one-page PDF with one text line per entry (Helvetica, WinAnsi). */
    private function pdf(array $lines): string
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
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function en(array $overrides = []): array
    {
        return array_replace_recursive([
            'number' => 'FA-2026-0042',
            'issue_date' => '2026-11-20',
            'seller' => ['name' => 'MARTIN DUPONT', 'legal_registration_identifier' => ['scheme' => '0002', 'value' => '123456782']],
            'buyer' => ['name' => 'Client Test SAS'],
            'lines' => [['identifier' => '1', 'net_amount' => '1250.00']],
            'vat_break_down' => [['vat_exemption_reason' => 'TVA non applicable, art. 293 B du CGI']],
            'totals' => ['total_with_vat' => '1250.00', 'amount_due_for_payment' => '1250.00'],
            'payment_due_date' => '2026-11-20',
            'notes' => [['subject_code' => 'PMT', 'note' => '…'], ['subject_code' => 'AAB', 'note' => '…']],
            'payment_instructions' => ['credit_transfers' => [['payment_account_identifier' => ['value' => 'FR7630006000011234567890189']]]],
        ], $overrides);
    }

    private function invoicePdf(array $replace = []): string
    {
        $lines = [
            'Martin DUPONT EI – Demo',
            'SIRET : 12345678200017',
            'Facture n° FA-2026-0042',
            'Date : 20/11/2026   Échéance : 20/11/2026',
            'Client Test SAS',
            'Développement   1 250,00 €',
            'Total : 1 250,00 €',
            'TVA non applicable, art. 293 B du CGI',
            'Indemnité forfaitaire pour frais de recouvrement : 40 €. Pas d\'escompte.',
            'IBAN : FR76 3000 6000 0112 3456 7890 189',
        ];

        return $this->pdf(array_map(fn ($l) => strtr($l, $replace), $lines));
    }

    public function test_consistent_pdf_and_data_pass_every_check(): void
    {
        $result = (new PdfCoherence)->check($this->invoicePdf(), $this->en());

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings'], implode(' | ', $result['warnings']));
        $this->assertGreaterThanOrEqual(10, count($result['checks']));
    }

    public function test_a_different_total_or_number_in_the_pdf_blocks(): void
    {
        $result = (new PdfCoherence)->check($this->invoicePdf(['1 250,00' => '1 205,00', 'FA-2026-0042' => 'FA-2026-0043']), $this->en());

        $this->assertGreaterThanOrEqual(3, count($result['errors']), implode(' | ', $result['errors']));
        $this->assertStringContainsString('ligne 1', implode(' ', $result['errors']));
        $this->assertStringContainsString('numéro', implode(' ', $result['errors']));
        $this->assertStringContainsString('total TTC', implode(' ', $result['errors']));
    }

    public function test_missing_vat_mention_blocks_and_missing_b2b_note_is_reported(): void
    {
        $result = (new PdfCoherence)->check($this->invoicePdf(['TVA non applicable, art. 293 B du CGI' => 'Exonération de TVA, article 261-4-4°a du CGI', 'Pas d\'escompte.' => '']), $this->en());

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('exonération', $result['errors'][0]);
        $this->assertStringContainsString('AAB', implode(' ', $result['warnings']));
    }

    public function test_dates_written_with_a_french_short_month_are_recognised(): void
    {
        $result = (new PdfCoherence)->check($this->invoicePdf(['Date : 20/11/2026   Échéance : 20/11/2026' => 'Date de la facture 20/nov./2026 Date d\'échéance 20 novembre 2026']), $this->en());

        $this->assertSame([], $result['errors'], implode(' | ', $result['errors']));
        $this->assertSame([], $result['warnings'], implode(' | ', $result['warnings']));
    }

    public function test_line_amount_formatting_is_the_same_amount(): void
    {
        $en = $this->en(['lines' => [['identifier' => '3', 'net_amount' => '130.00']], 'totals' => ['total_with_vat' => '130.00', 'amount_due_for_payment' => '130.00']]);
        $pdf = $this->invoicePdf([
            'Développement   1 250,00 €' => 'Ligne 3    130 €',
            'Total : 1 250,00 €' => 'Total : 130,00 EUR',
        ]);

        $result = (new PdfCoherence)->check($pdf, $en);

        $this->assertSame([], $result['errors'], implode(' | ', $result['errors']));
        $this->assertSame([], array_filter($result['warnings'], fn ($w) => str_contains($w, 'ligne')), implode(' | ', $result['warnings']));
    }

    public function test_a_real_line_amount_difference_blocks_the_invoice(): void
    {
        $en = $this->en(['lines' => [['identifier' => '3', 'net_amount' => '130.00']]]);
        $pdf = $this->invoicePdf(['Développement   1 250,00 €' => 'Ligne 3    150,00 €']);

        $result = (new PdfCoherence)->check($pdf, $en);

        $this->assertStringContainsString('ligne 3', implode(' ', $result['errors']));
        $this->assertNotContains(false, array_column(array_filter($result['checks'], fn ($c) => str_contains($c['label'], 'ligne 3')), 'blocking'));
    }

    public function test_unreadable_pdf_blocks_with_an_explicit_reason(): void
    {
        $result = (new PdfCoherence)->check('%PDF-1.4 pas un vrai PDF', $this->en());

        $this->assertSame([], $result['warnings']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Transmission bloquée', $result['errors'][0]);
        $this->assertStringContainsString('illisible', $result['errors'][0]);
    }

    public function test_a_pdf_without_extractable_text_blocks(): void
    {
        $empty = "%PDF-1.1\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n168\n%%EOF";
        $result = (new PdfCoherence)->check($empty, $this->en());

        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('Transmission bloquée', implode(' ', $result['errors']));
    }

    public function test_the_recovery_indemnity_does_not_validate_a_line(): void
    {
        $en = $this->en([
            'lines' => [[
                'identifier' => '1',
                'name' => 'Abonnement',
                'net_amount' => '40.00',
                'vat_rate' => '0',
                'vat_amount' => '0.00',
            ]],
            'totals' => ['amount_without_vat' => '40.00', 'vat_amount' => '0.00', 'amount_with_vat' => '40.00', 'amount_due' => '40.00'],
        ]);
        $result = (new PdfCoherence)->check($this->invoicePdf(), $en);

        $this->assertNotSame([], array_filter($result['errors'], fn ($error) => str_contains($error, 'ligne 1')));
    }

    public function test_two_identical_lines_need_two_printed_amounts(): void
    {
        $pdf = $this->invoicePdf([
            'Développement   1 250,00 €' => "Prestation A   100,00 €\nPrestation B   80,00 €",
            'Total : 1 250,00 €' => 'Total : 180,00 €',
            'Net à payer : 1 250,00 €' => 'Net à payer : 180,00 €',
        ]);
        $en = $this->en([
            'lines' => [
                ['identifier' => '1', 'name' => 'Prestation A', 'net_amount' => '100.00', 'vat_rate' => '0', 'vat_amount' => '0.00'],
                ['identifier' => '2', 'name' => 'Prestation B', 'net_amount' => '100.00', 'vat_rate' => '0', 'vat_amount' => '0.00'],
            ],
            'totals' => ['amount_without_vat' => '180.00', 'vat_amount' => '0.00', 'amount_with_vat' => '180.00', 'amount_due' => '180.00'],
        ]);
        $result = (new PdfCoherence)->check($pdf, $en);

        $this->assertNotSame([], array_filter($result['errors'], fn ($error) => str_contains($error, 'ligne 2')));
        $this->assertSame([], array_filter($result['errors'], fn ($error) => str_contains($error, 'ligne 1')));
    }
}
