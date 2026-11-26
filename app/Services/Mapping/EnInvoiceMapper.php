<?php

namespace App\Services\Mapping;

use Carbon\Carbon;

/**
 * Invoice Ninja invoice / credit (REST API JSON, client included) → SUPER PDP
 * `en_invoice` JSON (EN16931 + French CTC rules of XP Z12-012).
 *
 * The French rules are adapted from LightD31/invoiceninja-superpdp
 * (AGPL-3.0), rewritten to work from the public API instead of Invoice Ninja
 * internals.
 */
class EnInvoiceMapper
{
    public const SPECIFICATION = 'urn:cen.eu:en16931:2017';

    public const TYPE_INVOICE = 380;

    public const TYPE_CREDIT_NOTE = 381;

    public const TYPE_DEPOSIT = 386;

    /** @var string[] */
    private array $warnings = [];

    /**
     * @param  array  $doc  Invoice Ninja invoice or credit, with `client` (and `client.contacts`)
     * @param  array  $seller  resolved seller identity (see SellerResolver)
     * @param  callable(string|int|null): ?string  $country  Invoice Ninja country_id → ISO 3166-1 alpha-2
     * @param  array  $options  kind (invoice|credit), deposit (bool), preceding (list of [reference, issue_date]),
     *                          sandbox_buyer_address (?string)
     * @return array{invoice: array, processing_rule: string, type_code: int, warnings: string[], buyer_siren: ?string, total_with_vat: float}
     */
    public function map(array $doc, array $seller, callable $country, array $options = []): array
    {
        $this->warnings = [];

        $kind = $options['kind'] ?? 'invoice';
        $isCredit = $kind === 'credit';
        $deposit = ! $isCredit && ($options['deposit'] ?? false);
        $client = $doc['client'] ?? throw new MappingException('Le client de la facture est introuvable.');

        $number = $this->text($doc['number'] ?? '');
        if ($number === '') {
            throw new MappingException('Le document n\'a pas encore de numéro : marquez-le comme envoyé dans Invoice Ninja.');
        }

        if (blank($doc['date'] ?? null)) {
            throw new MappingException('Le document n\'a pas de date.');
        }

        $sellerCountry = strtoupper($seller['country'] ?? 'FR');
        if ($sellerCountry !== 'FR') {
            throw new MappingException("Seuls les vendeurs établis en France sont gérés (pays : {$sellerCountry}).");
        }

        $sellerSiren = $this->sirenOnly((string) ($seller['siren'] ?? ''), 'du vendeur (EINVOICING_SELLER_SIREN ou compte SUPER PDP)');
        $sellerVat = $this->vatNumber((string) ($seller['vat_number'] ?? ''), 'FR');

        $buyerCountry = $country($client['country_id'] ?? null) ?? $sellerCountry;
        $rule = $this->processingRule($client, $buyerCountry);
        $currency = $this->currency($client, $options);

        $lines = $this->lines($doc, (bool) ($client['is_tax_exempt'] ?? false));
        [$allowances, $charges] = $this->documentAllowancesAndCharges($doc, $lines['groups'], $options['surcharge_labels'] ?? []);
        $groups = $this->closeGroups($lines['groups']);
        $totals = $this->totals($doc, $isCredit, $lines['sum'], $allowances, $charges, $groups);

        $typeCode = $isCredit ? self::TYPE_CREDIT_NOTE : ($deposit ? self::TYPE_DEPOSIT : self::TYPE_INVOICE);
        $buyer = $this->buyer($client, $rule, $buyerCountry, (string) ($seller['email'] ?? ''), $options['sandbox_buyer_address'] ?? null);

        $invoice = [
            'number' => $number,
            'type_code' => $typeCode,
            'issue_date' => $this->date($doc['date']),
            'currency_code' => $currency,
            'process_control' => [
                'specification_identifier' => self::SPECIFICATION,
                'business_process_type' => $this->businessProcess($doc, $isCredit),
            ],
            'notes' => $this->notes($doc, $rule),
            'purchase_order_reference' => $this->text($doc['po_number'] ?? ''),
            'preceding_invoice_references' => $this->preceding($options['preceding'] ?? [], $isCredit),
            'seller' => [
                'name' => $this->required($this->text($seller['name'] ?? ''), 'Le nom du vendeur est vide.'),
                'trading_name' => $this->text($seller['trading_name'] ?? ''),
                'postal_address' => $this->address($seller['address_line1'] ?? '', $seller['address_line2'] ?? '', $seller['city'] ?? '', $seller['post_code'] ?? '', '', $sellerCountry),
                'legal_registration_identifier' => ['scheme' => '0002', 'value' => $sellerSiren],
                'identifiers' => filled($seller['siret'] ?? null) ? [['scheme' => '0009', 'value' => preg_replace('/\D/', '', $seller['siret'])]] : null,
                'electronic_address' => $this->electronicAddress((string) ($seller['electronic_address'] ?? '') ?: $sellerSiren),
                'vat_identifier' => $sellerVat,
                // BR-S/E/Z/AE-02: without a VAT number the SIREN is the tax registration (BT-32).
                'tax_registration_identifier' => $sellerVat ? null : $sellerSiren,
                'contact' => [
                    'email_address' => $this->text($seller['email'] ?? ''),
                    'phone_number' => $this->text($seller['phone'] ?? ''),
                ],
            ],
            'buyer' => $buyer,
            'lines' => $lines['lines'],
            'document_level_allowances' => $allowances ?: null,
            'charges' => $charges ?: null,
            'vat_break_down' => array_map(fn (array $g) => [
                'vat_category_code' => $g['category'],
                'vat_category_rate' => $this->money($g['rate']),
                'vat_category_taxable_amount' => $this->money($g['taxable']),
                'vat_category_tax_amount' => $this->money($g['tax']),
                'vat_exemption_reason' => $g['reason'],
                'vat_exemption_reason_code' => $g['reason_code'],
            ], array_values($groups)),
            'totals' => [
                'sum_invoice_lines_amount' => $this->money($lines['sum']),
                'sum_allowances_amount' => $allowances ? $this->money($totals['allowances']) : null,
                'sum_charges_amount' => $charges ? $this->money($totals['charges']) : null,
                'total_without_vat' => $this->money($totals['net']),
                'total_vat_amount' => ['value' => $this->money($totals['vat']), 'currency_code' => $currency],
                'total_with_vat' => $this->money($totals['gross']),
                'paid_amount' => $totals['paid'] != 0 ? $this->money($totals['paid']) : null,
                'rounding_amount' => $totals['rounding'] != 0 ? $this->money($totals['rounding']) : null,
                'amount_due_for_payment' => $this->money($totals['due']),
            ],
        ];

        // BR-FR-CO-12: outside EUR, the VAT total must also be given in EUR (BT-6, BT-111).
        if ($currency !== 'EUR') {
            $invoice['vat_accounting_currency_code'] = 'EUR';
            $invoice['totals']['total_vat_amount_accounting_currency'] = ['value' => $this->money($this->vatInEuro($totals['vat'], $doc)), 'currency_code' => 'EUR'];
        }

        if (str_ends_with($invoice['process_control']['business_process_type'], '2')) {
            $invoice['payment_due_date'] = $this->date($this->advancePayments($doc)['date']);
        } elseif (! $isCredit && filled($doc['due_date'] ?? null)) {
            $invoice['payment_due_date'] = $this->date($doc['due_date']);
        } elseif ($totals['due'] > 0) {
            // BR-CO-25: a positive amount due needs a due date or payment terms.
            $invoice['payment_terms'] = $options['payment_terms'] ?? 'Paiement à réception de facture.';
        }

        if (! $isCredit && filled($seller['iban'] ?? null)) {
            $iban = strtoupper(preg_replace('/\s+/', '', $seller['iban']));
            $invoice['payment_instructions'] = [
                'payment_means_type_code' => preg_match('/^(FR|DE|BE|ES|IT|NL|LU|PT|IE|AT|FI)\d/', $iban) ? '58' : '30',
                'credit_transfers' => [[
                    'payment_account_identifier' => ['scheme' => '', 'value' => $iban],
                    'payment_service_provider_identifier' => $this->text($seller['bic'] ?? ''),
                ]],
                'remittance_information' => $number,
            ];
        }

        $invoice = array_merge($invoice, $this->delivery($doc, $client, $buyerCountry, $country));

        return [
            'invoice' => $this->clean($invoice),
            'processing_rule' => $rule,
            'type_code' => $typeCode,
            'warnings' => $this->warnings,
            'buyer_siren' => $buyer['legal_registration_identifier']['value'] ?? null,
            'buyer_electronic_address' => isset($buyer['electronic_address']) ? $buyer['electronic_address']['scheme'].':'.$buyer['electronic_address']['value'] : null,
            'total_with_vat' => round($totals['gross'] + $totals['rounding'], 2),
        ];
    }

    private function processingRule(array $client, string $buyerCountry): string
    {
        return match (true) {
            ($client['classification'] ?? '') === 'individual' => 'B2C',
            ($client['classification'] ?? '') === 'government' => throw new MappingException('Les clients publics (B2G, Chorus Pro) ne sont pas gérés par le connecteur.'),
            $buyerCountry === 'FR' => 'B2B',
            default => 'B2BInt',
        };
    }

    /** Invoice Ninja's exchange_rate gives client-currency units per company-currency (EUR) unit. */
    private function vatInEuro(float $vat, array $doc): float
    {
        if (abs($vat) < 0.005) {
            return 0.0;
        }

        $rate = (float) ($doc['exchange_rate'] ?? 0);

        if ($rate <= 0) {
            throw new MappingException('Facture en devise étrangère avec TVA : le taux de change (Invoice Ninja) est requis pour exprimer la TVA en euros (BT-111).');
        }

        return round($vat / $rate, 2);
    }

    private function currency(array $client, array $options): string
    {
        $code = $options['currency_code'] ?? null;

        return is_string($code) && strlen($code) === 3 ? strtoupper($code) : 'EUR';
    }

    private function buyer(array $client, string $rule, string $country, string $sellerEmail, ?string $sandboxAddress): array
    {
        $name = $this->required($this->text($client['name'] ?? $client['display_name'] ?? ''), 'Le client n\'a pas de nom.');
        $contacts = collect($client['contacts'] ?? [])->sortByDesc(fn ($c) => (int) ($c['is_primary'] ?? 0));
        $email = $this->text($contacts->first()['email'] ?? '');

        $buyer = [
            'name' => $name,
            'postal_address' => $this->address($client['address1'] ?? '', $client['address2'] ?? '', $client['city'] ?? '', $client['postal_code'] ?? '', $client['state'] ?? '', $country),
            'vat_identifier' => $this->vatNumber((string) ($client['vat_number'] ?? ''), $country),
            'contact' => ['email_address' => $email],
        ];

        if ($rule === 'B2B') {
            [$siren, $siret] = $this->siren((string) ($client['id_number'] ?? ''), "du client « {$name} » (champ « N° d'identification » dans Invoice Ninja). S'il s'agit d'un particulier, choisissez la classification « Particulier »");
            $buyer['legal_registration_identifier'] = ['scheme' => '0002', 'value' => $siren];
            $buyer['identifiers'] = $siret ? [['scheme' => '0009', 'value' => $siret]] : null;
            $address = $this->text($client['routing_id'] ?? '') ?: $siren;

            if ($sandboxAddress !== null) {
                if ($sandboxAddress === '') {
                    throw new MappingException('Bac à sable : définissez SUPERPDP_SANDBOX_BUYER_ADDRESS (adresse d\'une entreprise de test, ex. 315143296_XXX) pour ne jamais adresser un vrai client.');
                }

                $this->warnings[] = "Bac à sable : l'adresse du client ({$address}) est remplacée par l'adresse de test {$sandboxAddress}.";
                $address = $sandboxAddress;
            }

            $buyer['electronic_address'] = $this->electronicAddress($address);

            return $buyer;
        }

        if (filled($client['routing_id'] ?? null)) {
            $buyer['electronic_address'] = $this->electronicAddress($client['routing_id']);
        } elseif ($email !== '') {
            // BR-FR-12 wants BT-49 even when nothing is routed; an e-mail address is accepted.
            $buyer['electronic_address'] = ['scheme' => 'EM', 'value' => $email];
        } elseif ($sellerEmail !== '') {
            $buyer['electronic_address'] = ['scheme' => 'EM', 'value' => $sellerEmail];
            $this->warnings[] = "Le client « {$name} » n'a pas d'e-mail : l'adresse électronique de l'acheteur (BT-49) reprend celle du vendeur.";
        } else {
            throw new MappingException("Le client « {$name} » n'a ni e-mail ni routing ID : impossible de remplir l'adresse électronique de l'acheteur (BT-49).");
        }

        return $buyer;
    }

    /** @return array{lines: array, sum: float, groups: array<string, array>} */
    private function lines(array $doc, bool $clientExempt): array
    {
        $inclusive = (bool) ($doc['uses_inclusive_taxes'] ?? false);
        $documentRates = array_values(array_filter([(float) ($doc['tax_rate1'] ?? 0), (float) ($doc['tax_rate2'] ?? 0), (float) ($doc['tax_rate3'] ?? 0)]));

        $lines = [];
        $groups = [];
        $sum = 0.0;
        $n = 0;

        foreach ((array) ($doc['line_items'] ?? []) as $item) {
            $cost = (float) ($item['cost'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            $name = $this->text($item['product_key'] ?? '');
            $notes = $this->text($item['notes'] ?? '');

            if ($name === '' && $notes === '' && $cost == 0.0) {
                continue;
            }

            $n++;

            $rates = array_values(array_unique(array_merge(
                array_values(array_filter([(float) ($item['tax_rate1'] ?? 0), (float) ($item['tax_rate2'] ?? 0), (float) ($item['tax_rate3'] ?? 0)])),
                $documentRates,
            )));

            if (count($rates) > 1) {
                throw new MappingException("Ligne {$n} : plusieurs taux de taxe cumulés (".implode(' % + ', $rates).' %). Une facture électronique n\'accepte qu\'un taux de TVA par ligne.');
            }

            [$category, $rate, $reasonCode, $reason] = $this->vatCategory((float) ($rates[0] ?? 0), (string) ($item['tax_id'] ?? ''), $clientExempt, $n);

            $lineTotal = (float) ($item['line_total'] ?? $cost * $qty);
            $net = round($inclusive ? $lineTotal / (1 + $rate / 100) : $lineTotal, 2);

            // Unit prices are VAT exclusive and never negative (BR-27/28): a
            // negative line carries a negative quantity instead.
            $unitCost = $inclusive ? $cost / (1 + $rate / 100) : $cost;
            $netPrice = $qty != 0.0 ? $net / $qty : $unitCost;

            if ($netPrice < 0 || ($qty == 0.0 && $net < 0)) {
                $qty = -$qty;
                $netPrice = -$netPrice;
                $unitCost = -$unitCost;
            }

            $price = ['item_net_price' => $this->decimal($netPrice)];

            if ($qty != 0.0 && abs($unitCost * $qty - $net) > 0.005 && $unitCost >= $netPrice) {
                $price = [
                    'item_gross_price' => $this->decimal($unitCost),
                    'item_price_discount' => $this->decimal($unitCost - (float) $this->decimal($netPrice)),
                    'item_net_price' => $this->decimal($netPrice),
                ];
            }

            $unit = (string) ($item['unit_code'] ?? '') ?: 'C62';
            if ($unit === 'C62' && (string) ($item['type_id'] ?? '') === '2') {
                $unit = 'HUR'; // task lines are hours
            }

            if ($name === '') {
                $name = $notes !== '' ? mb_strimwidth(explode("\n", $notes)[0], 0, 120, '…') : "Ligne {$n}";
            }

            $line = [
                'identifier' => (string) $n,
                'invoiced_quantity' => $this->decimal($qty),
                'invoiced_quantity_code' => $unit,
                'net_amount' => $this->money($net),
                'item_information' => [
                    'name' => $name,
                    'description' => $notes !== '' && $notes !== $name ? $notes : null,
                ],
                'price_details' => $price,
                'vat_information' => [
                    'invoiced_item_vat_category_code' => $category,
                    'invoiced_item_vat_rate' => $this->money($rate),
                ],
            ];

            if ($date = $this->lineDate($item['date'] ?? null)) {
                $line['period'] = ['start_date' => $date, 'end_date' => $date];
            }

            $lines[] = $line;

            $key = $category.'|'.$this->money($rate);
            $groups[$key] ??= ['category' => $category, 'rate' => $rate, 'reason' => $reason, 'reason_code' => $reasonCode, 'net' => 0.0, 'allowances' => 0.0, 'charges' => 0.0];
            $groups[$key]['net'] += $net;
            $sum += $net;
        }

        if ($lines === []) {
            throw new MappingException('Le document n\'a aucune ligne.');
        }

        return ['lines' => $lines, 'sum' => round($sum, 2), 'groups' => $groups];
    }

    /** @return array{0: string, 1: float, 2: ?string, 3: ?string} category, rate, exemption code, exemption text */
    private function vatCategory(float $rate, string $taxId, bool $clientExempt, int $n): array
    {
        $franchise = (bool) config('einvoicing.vat.franchise', true);

        if ($rate > 0) {
            if ($franchise) {
                throw new MappingException("Ligne {$n} : TVA à {$rate} % alors que l'entreprise est déclarée en franchise en base (EINVOICING_VAT_FRANCHISE=true).");
            }

            return ['S', $rate, null, null];
        }

        // Invoice Ninja product tax categories: 8 zero rated, 9 reverse charge.
        if (! $franchise && $taxId === '9') {
            return ['AE', 0.0, 'VATEX-EU-AE', 'Autoliquidation'];
        }

        if (! $franchise && $taxId === '8') {
            return ['Z', 0.0, null, null];
        }

        $code = (string) config('einvoicing.vat.exemption_code');
        $text = (string) config('einvoicing.vat.exemption_text');

        if ($text === '' && $code === '') {
            throw new MappingException("Ligne {$n} : TVA à 0 % sans motif d'exonération (EINVOICING_VAT_EXEMPTION_CODE / _TEXT).");
        }

        if ($clientExempt && ! $franchise) {
            $this->warnings[] = "Ligne {$n} : client marqué exonéré de taxe, motif utilisé : {$text}.";
        }

        return ['E', 0.0, $code !== '' ? $code : null, $text !== '' ? $text : null];
    }

    /** Invoice discount and custom surcharges, split across VAT groups in proportion to their net amounts. */
    private function documentAllowancesAndCharges(array $doc, array &$groups, array $labels): array
    {
        $allowances = [];
        $charges = [];
        $base = array_sum(array_column($groups, 'net'));

        $discount = (float) ($doc['discount'] ?? 0);

        if ($discount != 0.0) {
            $amount = ($doc['is_amount_discount'] ?? false) ? $discount : round($base * $discount / 100, 2);
            $this->split($groups, $amount, 'allowances', $allowances, 'Remise', ($doc['is_amount_discount'] ?? false) ? null : $discount);
        }

        foreach ([1, 2, 3, 4] as $i) {
            $amount = (float) ($doc["custom_surcharge{$i}"] ?? 0);

            if ($amount == 0.0) {
                continue;
            }

            $label = $this->text($labels["surcharge{$i}"] ?? '') ?: 'Frais';

            if (array_filter(array_column($groups, 'rate'))) {
                throw new MappingException("Frais « {$label} » sur une facture avec TVA : non pris en charge, ajoutez-les comme une ligne de la facture.");
            }

            if ($amount > 0) {
                $this->split($groups, $amount, 'charges', $charges, $label);
            } else {
                $this->split($groups, -$amount, 'allowances', $allowances, $label);
            }
        }

        if (($allowances || $charges) && ($doc['uses_inclusive_taxes'] ?? false)) {
            throw new MappingException('Remise globale ou frais avec des taxes incluses : non pris en charge, utilisez des prix HT.');
        }

        return [$allowances, $charges];
    }

    private function split(array &$groups, float $amount, string $kind, array &$out, string $reason, ?float $percent = null): void
    {
        $base = array_sum(array_column($groups, 'net'));
        $keys = array_keys($groups);
        $left = round($amount, 2);

        foreach ($keys as $i => $key) {
            $part = $i === count($keys) - 1 || $base == 0.0 ? $left : round($amount * $groups[$key]['net'] / $base, 2);
            $left = round($left - $part, 2);
            $groups[$key][$kind] += $part;

            $out[] = [
                'amount' => $this->money($part),
                'base_amount' => $percent !== null ? $this->money($groups[$key]['net']) : null,
                'percent' => $percent !== null ? $this->money($percent) : null,
                'reason' => $reason,
                'reason_code' => $kind === 'allowances' && $reason === 'Remise' ? '95' : null,
                'vat_category_code' => $groups[$key]['category'],
                'vat_rate' => $this->money($groups[$key]['rate']),
                'vat_exemption_reason' => $groups[$key]['reason'],
                'vat_exemption_reason_code' => $groups[$key]['reason_code'],
            ];
        }
    }

    private function closeGroups(array $groups): array
    {
        foreach ($groups as &$g) {
            $g['taxable'] = round($g['net'] - $g['allowances'] + $g['charges'], 2);
            $g['tax'] = round($g['taxable'] * $g['rate'] / 100, 2);
        }

        return $groups;
    }

    private function totals(array $doc, bool $isCredit, float $sum, array $allowances, array $charges, array $groups): array
    {
        $allowanceTotal = round(array_sum(array_map(fn ($a) => (float) $a['amount'], $allowances)), 2);
        $chargeTotal = round(array_sum(array_map(fn ($c) => (float) $c['amount'], $charges)), 2);
        $net = round($sum - $allowanceTotal + $chargeTotal, 2);
        $vat = round(array_sum(array_column($groups, 'tax')), 2);
        $gross = round($net + $vat, 2);

        // Invoice Ninja rounds taxes per line, EN16931 per VAT group. Small
        // differences go to BT-114 so the amount due matches the PDF.
        $rounding = round((float) ($doc['amount'] ?? 0) - $gross, 2);

        if (abs($rounding) > 0.05) {
            throw new MappingException(sprintf('Total incohérent : %s dans Invoice Ninja, %s recalculé pour la facture électronique.', $this->money((float) ($doc['amount'] ?? 0)), $this->money($gross)));
        }

        // BT-113 is what was paid before the invoice was issued, not what was paid since.
        $paid = $isCredit ? 0.0 : min($this->advancePayments($doc)['amount'], round($gross + $rounding, 2));

        if ($gross + $rounding <= 0 && ! $isCredit) {
            throw new MappingException('Facture à montant nul ou négatif : émettez un avoir à la place.');
        }

        return [
            'allowances' => $allowanceTotal,
            'charges' => $chargeTotal,
            'net' => $net,
            'vat' => $vat,
            'gross' => $gross,
            'rounding' => $rounding,
            'paid' => $paid,
            'due' => round($gross + $rounding - $paid, 2),
        ];
    }

    private function businessProcess(array $doc, bool $isCredit): string
    {
        $letter = in_array(config('einvoicing.business_process'), ['B', 'S', 'M'], true) ? config('einvoicing.business_process') : 'S';

        // "2" = already paid when issued; BR-FR-CO-09 then requires BT-9 = payment date.
        $paidAtIssue = ! $isCredit && $this->advancePayments($doc)['amount'] >= round((float) ($doc['amount'] ?? 0), 2) - 0.005 && (float) ($doc['amount'] ?? 0) > 0;

        return $letter.($paidAtIssue ? '2' : '1');
    }

    /** Payments dated on or before the issue date, as applied to this invoice. */
    private function advancePayments(array $doc): array
    {
        $issue = substr((string) ($doc['date'] ?? ''), 0, 10);
        $amount = 0.0;
        $last = null;

        foreach ((array) ($doc['payments'] ?? []) as $payment) {
            $date = substr((string) ($payment['date'] ?? ''), 0, 10);

            if (($payment['is_deleted'] ?? false) || $date === '' || $date > $issue) {
                continue;
            }

            $applied = collect($payment['paymentables'] ?? [])
                ->filter(fn ($p) => ($p['invoice_id'] ?? null) === ($doc['id'] ?? null))
                ->sum(fn ($p) => (float) ($p['amount'] ?? 0) - (float) ($p['refunded'] ?? 0));

            if ($applied > 0) {
                $amount += $applied;
                $last = max($last ?? $date, $date);
            }
        }

        return ['amount' => round($amount, 2), 'date' => $last];
    }

    private function notes(array $doc, string $rule): array
    {
        $notes = [];

        foreach ((array) config('einvoicing.notes') as $code => $text) {
            if (filled($text)) {
                $notes[] = ['note' => $text, 'subject_code' => $code];
            }
        }

        if ($rule === 'B2C') {
            $notes[] = ['note' => 'B2C', 'subject_code' => 'BAR'];
        }

        if (($public = $this->text($doc['public_notes'] ?? '')) !== '') {
            $notes[] = ['note' => $public, 'subject_code' => 'AAI'];
        }

        return $notes;
    }

    private function preceding(array $preceding, bool $isCredit): ?array
    {
        if ($isCredit && $preceding === []) {
            throw new MappingException('Un avoir doit référencer la facture qu\'il corrige (BR-FR-CO-05) : créez-le depuis une facture payée dans Invoice Ninja (Actions → Rembourser), ou saisissez le numéro de la facture d\'origine dans le champ « N° de bon de commande » de l\'avoir.');
        }

        $out = [];

        // The preceding invoice type code only exists in the Factur-X EXTENDED profile:
        // SUPER PDP rejects it when converting to the EN16931 profile.
        foreach ($preceding as $p) {
            $out[] = array_filter([
                'reference' => (string) $p['reference'],
                'issue_date' => isset($p['issue_date']) ? $this->date($p['issue_date']) : null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $out ?: null;
    }

    private function delivery(array $doc, array $client, string $buyerCountry, callable $country): array
    {
        $out = [];
        $dates = collect($doc['line_items'] ?? [])->map(fn ($i) => $this->lineDate($i['date'] ?? null))->filter()->values()->all();

        if (count($dates) === 1 || (count($dates) > 1 && min($dates) === max($dates))) {
            $out['delivery_information'] = ['delivery_date' => $dates[0]];
        } elseif (count($dates) > 1) {
            $out['invoicing_period'] = ['start_date' => min($dates), 'end_date' => max($dates)];
        }

        $shipping = $this->text($client['shipping_address1'] ?? '');

        if ($shipping !== '' && $shipping !== $this->text($client['address1'] ?? '')) {
            // New French mandatory mention: delivery address when it differs from the billing address.
            $out['deliver_to_address'] = $this->address(
                $client['shipping_address1'] ?? '',
                $client['shipping_address2'] ?? '',
                $client['shipping_city'] ?? '',
                $client['shipping_postal_code'] ?? '',
                $client['shipping_state'] ?? '',
                $country($client['shipping_country_id'] ?? null) ?? $buyerCountry,
            );
        }

        return $out;
    }

    private function lineDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: string, 1: ?string} SIREN and, when a SIRET was given, the SIRET */
    private function siren(string $raw, string $where): array
    {
        $digits = preg_replace('/\D/', '', $raw);

        if (strlen($digits) === 14) {
            return [substr($digits, 0, 9), $digits];
        }

        if (strlen($digits) === 9) {
            return [$digits, null];
        }

        // "SIRET : 12345678900012 / NDA : ..." : take the first 14 or 9 digit run.
        if (preg_match('/\b(\d{3}\s?\d{3}\s?\d{3}(?:\s?\d{5})?)\b/', $raw, $m)) {
            return $this->siren($m[1], $where);
        }

        throw new MappingException("SIREN (9 chiffres) ou SIRET (14 chiffres) manquant ou invalide {$where}.");
    }

    private function sirenOnly(string $raw, string $where): string
    {
        return $this->siren($raw, $where)[0];
    }

    private function vatNumber(string $raw, string $country): ?string
    {
        $vat = strtoupper(preg_replace('/[\s.\-]/', '', $raw));
        $vat = preg_replace('/^(TVA|VAT|NTVA)[:]?/', '', $vat);

        if ($vat === '') {
            return null;
        }

        return ctype_alpha(substr($vat, 0, 2)) ? $vat : $country.$vat;
    }

    /** "0225:123456789", "EM:x@y" or a bare French address (scheme 0225). */
    private function electronicAddress(string $raw): array
    {
        $raw = trim($raw);

        if (preg_match('/^([A-Z0-9]{2,4}):(.+)$/i', $raw, $m)) {
            return ['scheme' => strtoupper($m[1]), 'value' => trim($m[2])];
        }

        return ['scheme' => '0225', 'value' => $raw];
    }

    private function address(?string $line1, ?string $line2, ?string $city, ?string $postCode, ?string $state, string $country): array
    {
        return [
            'address_line1' => $this->text($line1),
            'address_line2' => $this->text($line2),
            'city' => $this->text($city),
            'post_code' => $this->text($postCode),
            'country_subdivision' => $this->text($state),
            'country_code' => $country,
        ];
    }

    private function date(mixed $value): string
    {
        return Carbon::parse($value)->toDateString();
    }

    private function money(float $value): string
    {
        $value = round($value, 2);

        return number_format($value == 0.0 ? 0.0 : $value, 2, '.', '');
    }

    private function decimal(float $value, int $scale = 6): string
    {
        $s = rtrim(rtrim(number_format(round($value, $scale), $scale, '.', ''), '0'), '.');

        return $s === '-0' || $s === '' ? '0' : $s;
    }

    private function text(mixed $value): string
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t]+/', ' ', preg_replace("/\r\n?/", "\n", $value)));
    }

    private function required(string $value, string $message): string
    {
        return $value !== '' ? $value : throw new MappingException($message);
    }

    /** Drop null, '' and empty arrays, recursively. */
    private function clean(array $data): array
    {
        $list = array_is_list($data);

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $v = $this->clean($v);
            }

            if ($v === null || $v === '' || $v === []) {
                unset($data[$k]);
            } else {
                $data[$k] = $v;
            }
        }

        return $list ? array_values($data) : $data;
    }
}
