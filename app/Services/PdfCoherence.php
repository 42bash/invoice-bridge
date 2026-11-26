<?php

namespace App\Services;

use Carbon\Carbon;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Compares the human-readable PDF (rendered by Invoice Ninja) with the
 * structured EN16931 data built from the same invoice. The PDF and the XML of
 * a Factur-X must say the same thing: a mismatch on the number, date, total,
 * seller or VAT mention blocks the deposit; softer differences are reported.
 */
class PdfCoherence
{
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    // As written by Invoice Ninja in French (date format « d/M/Y » gives 08/sept./2026).
    private const MONTHS_SHORT = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    /** @return array{errors: list<string>, warnings: list<string>, checks: list<array{label: string, expected: string, found: bool, blocking: bool}>} */
    public function check(string $pdf, array $en): array
    {
        $result = ['errors' => [], 'warnings' => [], 'checks' => []];

        try {
            $loose = self::normalize((new Parser)->parseContent($pdf)->getText(), false);
            $text = self::collapse($loose);
        } catch (Throwable $e) {
            $result['errors'][] = 'Transmission bloquée : le PDF est illisible, la cohérence avec les données structurées ne peut pas être établie ('.mb_strimwidth($e->getMessage(), 0, 120, '…').').';

            return $result;
        }

        if (mb_strlen($text) < 20) {
            $result['errors'][] = 'Transmission bloquée : le PDF ne contient pas de texte extractible, la cohérence avec les données structurées ne peut pas être établie.';

            return $result;
        }

        // A single space in « 1 250,00 » is a thousands separator. A wider gap is a
        // column, so « ligne 3    130 € » must not become 3 130 and then 3130.
        $compact = self::collapse($this->foldThousands($loose));
        $add = function (string $label, string $expected, bool $found, bool $blocking) use (&$result) {
            $result['checks'][] = compact('label', 'expected', 'found', 'blocking');

            if (! $found) {
                $result[$blocking ? 'errors' : 'warnings'][] = "PDF ⇔ XML : {$label} « {$expected} » absent du PDF.";
            }
        };

        $add('numéro (BT-1)', (string) $en['number'], str_contains($text, mb_strtolower((string) $en['number'])), true);

        if (filled($en['issue_date'] ?? null)) {
            $add('date d\'émission (BT-2)', Carbon::parse($en['issue_date'])->format('d/m/Y'), $this->containsDate($text, $en['issue_date']), true);
        }

        $total = (string) ($en['totals']['total_with_vat'] ?? '');
        if ($total !== '') {
            $add('total TTC (BT-112)', $total, $this->containsAmount($compact, $total), true);
        }

        $due = (string) ($en['totals']['amount_due_for_payment'] ?? '');
        if ($due !== '' && $due !== $total) {
            $add('montant à payer (BT-115)', $due, $this->containsAmount($compact, $due), false);
        }

        $siren = (string) ($en['seller']['legal_registration_identifier']['value'] ?? '');
        if (preg_match('/^\d{9}$/', $siren)) {
            $add('SIREN du vendeur (BT-30)', $siren, str_contains(preg_replace('/\s+/', '', $text), $siren), true);
        }

        $add('nom du vendeur (BT-27)', (string) $en['seller']['name'], $this->containsWords($text, (string) $en['seller']['name']), true);
        $add('nom du client (BT-44)', (string) $en['buyer']['name'], $this->containsWords($text, (string) $en['buyer']['name'], 0.6), false);

        foreach ($en['vat_break_down'] ?? [] as $vat) {
            if (filled($vat['vat_exemption_reason'] ?? null)) {
                $add('mention d\'exonération de TVA (BT-120)', $vat['vat_exemption_reason'], $this->containsWords($text, $vat['vat_exemption_reason'], 1.0), true);
            }
        }

        if (filled($en['payment_due_date'] ?? null)) {
            $add('échéance (BT-9)', Carbon::parse($en['payment_due_date'])->format('d/m/Y'), $this->containsDate($text, $en['payment_due_date']), false);
        }

        foreach (['PMT' => ['recouvrement', '40'], 'PMD' => ['pénalités'], 'AAB' => ['escompte']] as $code => $keywords) {
            if (collect($en['notes'] ?? [])->contains(fn ($n) => ($n['subject_code'] ?? null) === $code)) {
                $add("mention B2B {$code}", implode(' + ', $keywords), collect($keywords)->every(fn ($k) => str_contains($text, $k)), false);
            }
        }

        foreach ($en['payment_instructions']['credit_transfers'] ?? [] as $transfer) {
            $iban = (string) ($transfer['payment_account_identifier']['value'] ?? '');
            if ($iban !== '') {
                $add('IBAN (BT-84)', substr($iban, 0, 4).'…'.substr($iban, -4), str_contains(preg_replace('/\s+/', '', $text), mb_strtolower($iban)), false);
            }
        }

        $used = [];

        foreach ($en['lines'] ?? [] as $line) {
            $add(
                'montant de la ligne '.$line['identifier'].' (BT-131)',
                (string) $line['net_amount'],
                $this->lineAmountFound($compact, (string) $line['net_amount'], $used),
                true,
            );
        }

        return $result;
    }

    public static function normalize(string $text, bool $collapse = true): string
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\t", "\r", "\n"], ' ', $text);
        $text = str_replace(['’', '‘'], "'", $text);
        $text = mb_strtolower($text);

        return $collapse ? self::collapse($text) : trim($text);
    }

    private static function collapse(string $text): string
    {
        return trim(preg_replace('/ {2,}/', ' ', $text) ?? $text);
    }

    /** Joins « 1 250,00 » into « 1250,00 » without merging a column gap. */
    private function foldThousands(string $text): string
    {
        return preg_replace('/(?<=\d)(?:[.\'\x{2019}]| )(?=\d{3}\b)/u', '', $text) ?? $text;
    }

    /**
     * A formatting difference (1 250,00 €, 1250.00, 130 €) is the same amount.
     * A bare integer that is only a date, a SIREN or a document number does not count:
     * money is a decimal amount or a figure written next to a currency sign.
     *
     * A line needs its own occurrence. A total, an amount due or the 40 € recovery
     * indemnity does not confirm a line that merely happens to show the same figure.
     */
    private function containsAmount(string $compact, string $amount): bool
    {
        $expected = round((float) $amount, 2);

        foreach ($this->moneyTokens($this->amountText($compact)) as $token) {
            if (abs($token['amount'] - $expected) < 0.005) {
                return true;
            }
        }

        return $expected < 0 && $this->containsAmount($compact, (string) abs($expected));
    }

    /** @param array<int, true> $used */
    private function lineAmountFound(string $compact, string $amount, array &$used): bool
    {
        $text = $this->amountText($compact);
        $expected = round((float) $amount, 2);
        $previous = 0;
        $tokens = $this->moneyTokens($text);
        usort($tokens, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        foreach ($tokens as $token) {
            $reserved = $this->isReservedAmount($text, $previous, $token['at']);
            $previous = $token['at'];

            if ($reserved || isset($used[$token['at']]) || abs($token['amount'] - $expected) >= 0.005) {
                continue;
            }

            $used[$token['at']] = true;

            return true;
        }

        return $expected < 0 && $this->lineAmountFound($compact, (string) abs($expected), $used);
    }

    /**
     * The words between this amount and the previous one say what the figure is.
     * A total, an amount due or the recovery indemnity stays unavailable for a line.
     */
    private function isReservedAmount(string $text, int $from, int $at): bool
    {
        $gap = substr($text, $from, max(0, $at - $from));

        if (strlen($gap) > 80) {
            $gap = substr($gap, -80);
        }

        return preg_match('/total|ttc|\bpayer\b|recouvrement|indemnit|p[eé]nalit/u', $gap) === 1;
    }

    private function amountText(string $text): string
    {
        return preg_replace('/(?<=\d)\s+(?=[.,])|(?<=[.,])\s+(?=\d)/u', '', $text) ?? $text;
    }

    /** @return list<array{amount: float, at: int}> */
    private function moneyTokens(string $text): array
    {
        // Thousands separators are already folded. A remaining space separates two figures.
        $pattern = '/(?<![\d])(?:\d{1,3}(?:[\x{00A0}\x{202F}.]\d{3})+(?:[.,]\d{1,2})?|\d+[.,]\d{1,2})(?![\d])/u';
        preg_match_all($pattern, $text, $decimals, PREG_OFFSET_CAPTURE);
        preg_match_all('/(?<![\d])(\d{1,3}(?:[\x{00A0}\x{202F}.]\d{3})+|\d+)\s*(?:€|eur)(?![\p{L}\d])/ui', $text, $withCurrency, PREG_OFFSET_CAPTURE);

        $tokens = [];

        foreach (array_merge($decimals[0], $withCurrency[1]) as [$raw, $at]) {
            $tokens[] = ['amount' => $this->parseAmount($raw), 'at' => $at];
        }

        return $tokens;
    }

    private function parseAmount(string $raw): float
    {
        $raw = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $raw);
        $comma = strrpos($raw, ',');
        $dot = strrpos($raw, '.');

        if ($comma !== false && $dot !== false) {
            $raw = $comma > $dot
                ? str_replace(',', '.', str_replace('.', '', $raw))
                : str_replace(',', '', $raw);
        } elseif ($comma !== false) {
            $raw = strlen($raw) - $comma - 1 <= 2 ? str_replace(',', '.', $raw) : str_replace(',', '', $raw);
        } elseif ($dot !== false && (strlen($raw) - $dot - 1 === 3 || substr_count($raw, '.') > 1)) {
            $raw = str_replace('.', '', $raw);
        }

        return (float) $raw;
    }

    private function containsDate(string $text, string $date): bool
    {
        $d = Carbon::parse($date);
        $candidates = [$d->format('d/m/Y'), $d->format('j/n/Y'), $d->format('d/m/y'), $d->format('Y-m-d'), $d->format('d-m-Y'), $d->format('d.m.Y')];
        $months = array_unique([
            self::MONTHS[$d->month - 1],
            self::MONTHS_SHORT[$d->month - 1],
            rtrim(self::MONTHS_SHORT[$d->month - 1], '.'),
            mb_strtolower($d->locale('en')->isoFormat('MMM')),
            mb_strtolower($d->locale('en')->isoFormat('MMMM')),
        ]);

        foreach ($months as $month) {
            foreach ([$d->format('d'), $d->format('j')] as $day) {
                array_push($candidates, "{$day}/{$month}/{$d->year}", "{$day} {$month} {$d->year}", "{$day}-{$month}-{$d->year}", "{$month} {$day}, {$d->year}", "{$month} {$day} {$d->year}");
            }
        }

        foreach ($candidates as $candidate) {
            if (str_contains($text, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** All significant words (or the given share of them) appear in the text. */
    private function containsWords(string $text, string $value, float $share = 1.0): bool
    {
        $plain = fn (string $s) => preg_replace('/[^a-z0-9 ]/', ' ', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $s)));
        $haystack = ' '.preg_replace('/ {2,}/', ' ', $plain($text)).' ';
        $words = array_values(array_filter(explode(' ', $plain(self::normalize($value))), fn ($w) => strlen($w) >= 2 && ! in_array($w, ['de', 'du', 'la', 'le', 'les', 'et', 'des', 'art', 'cgi'], true)));

        if ($words === []) {
            return true;
        }

        $found = count(array_filter($words, fn ($w) => str_contains($haystack, " {$w} ")));

        return $found / count($words) >= $share;
    }
}
