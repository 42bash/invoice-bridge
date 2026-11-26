@php
    $types = [380 => 'Facture', 381 => 'Avoir', 384 => 'Facture rectificative', 386 => 'Facture d\'acompte', 389 => 'Autofacture'];
    $frameworks = [
        'B1' => 'Biens — facture à payer', 'S1' => 'Prestations de services — facture à payer', 'M1' => 'Mixte — facture à payer',
        'B2' => 'Biens — facture déjà payée', 'S2' => 'Prestations de services — facture déjà payée', 'M2' => 'Mixte — facture déjà payée',
        'S3' => 'Prestations de services — facture d\'acompte', 'B4' => 'Biens — facture définitive après acompte', 'S4' => 'Prestations de services — facture définitive après acompte', 'M4' => 'Mixte — facture définitive après acompte',
    ];
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ').' '.($en['currency_code'] ?? 'EUR');
    $date = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '—';
    $mask = fn ($v) => strlen((string) $v) > 8 ? substr($v, 0, 4).' •••• '.substr($v, -4) : $v;
    $id = fn ($i) => isset($i['value']) ? (($i['scheme'] ?? '') !== '' ? $i['scheme'].' : ' : '').$i['value'] : '—';
    $party = fn ($p) => array_filter([
        'Nom' => $p['name'] ?? null,
        'Nom commercial' => $p['trading_name'] ?? null,
        'Adresse' => trim(implode(', ', array_filter([$p['postal_address']['address_line1'] ?? null, $p['postal_address']['address_line2'] ?? null, trim(($p['postal_address']['post_code'] ?? '').' '.($p['postal_address']['city'] ?? '')), $p['postal_address']['country_code'] ?? null]))),
        'Identifiant légal (SIREN)' => isset($p['legal_registration_identifier']) ? $id($p['legal_registration_identifier']) : null,
        'Autres identifiants (SIRET…)' => collect($p['identifiers'] ?? [])->map($id)->implode(', ') ?: null,
        'N° TVA' => $p['vat_identifier'] ?? null,
        'Adresse électronique (réception)' => isset($p['electronic_address']) ? $id($p['electronic_address']) : null,
        'Contact' => collect($p['contact'] ?? [])->filter()->implode(' · ') ?: null,
    ]);
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Aperçu e-facture {{ $en['number'] }}</title>
<style>
    body { font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1d2433; margin: 0; background: #f4f6f9; }
    main { max-width: 980px; margin: 0 auto; padding: 24px; }
    h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 15px; margin: 0 0 10px; color: #3b4a66; text-transform: uppercase; letter-spacing: .04em; }
    .sub { color: #5d6b85; margin-bottom: 16px; }
    .card { background: #fff; border: 1px solid #dde3ec; border-radius: 8px; padding: 16px 18px; margin-bottom: 14px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 0; } dt { color: #5d6b85; } dd { margin: 0; }
    table { width: 100%; border-collapse: collapse; } th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #edf0f5; vertical-align: top; }
    th { font-size: 12px; color: #5d6b85; font-weight: 600; } td.n, th.n { text-align: right; white-space: nowrap; }
    .bt { color: #8a96ab; font-size: 11px; margin-left: 4px; }
    .banner { border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; font-weight: 600; }
    .ok { background: #e7f6ec; color: #17633a; border: 1px solid #b9e3c7; } .ko { background: #fdecec; color: #8a1f1f; border: 1px solid #f3c2c2; } .warn { background: #fff6e0; color: #7a5300; border: 1px solid #f1dca4; }
    .lock { background: #eef2ff; color: #2c3d8f; border: 1px solid #cdd6fb; font-weight: 500; }
    ul { margin: 6px 0 0 18px; padding: 0; } .tot td { font-weight: 600; }
    .yes { color: #17633a; } .no { color: #b42318; font-weight: 600; } .soft { color: #a15c00; }
</style>
</head>
<body>
<main>
    <h1>{{ $types[$en['type_code']] ?? 'Document '.$en['type_code'] }} n° {{ $en['number'] }}</h1>
    <div class="sub">Aperçu des données structurées (norme EN16931) générées à partir d'Invoice Ninja le {{ \Carbon\Carbon::parse($report['generated_at'])->timezone('Europe/Paris')->format('d/m/Y à H:i') }}.</div>

    <div class="banner lock">Document local : non transmis à SUPER PDP ni à l'administration. Aucune conversion ni validation distante n'a été effectuée.</div>

    @if ($report['errors'])
        <div class="banner ko">{{ count($report['errors']) }} anomalie(s) bloquante(s) : ce document ne serait pas déposé en l'état.
            <ul>@foreach ($report['errors'] as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @else
        <div class="banner ok">Aucune anomalie bloquante : ce document serait proposé au dépôt (après validation par le validateur SUPER PDP).</div>
    @endif
    @if ($report['warnings'])
        <div class="banner warn">{{ count($report['warnings']) }} point(s) d'attention :
            <ul>@foreach ($report['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul></div>
    @endif

    <div class="card">
        <h2>Document</h2>
        <dl>
            <dt>Numéro<span class="bt">BT-1</span></dt><dd>{{ $en['number'] }}</dd>
            <dt>Date d'émission<span class="bt">BT-2</span></dt><dd>{{ $date($en['issue_date'] ?? null) }}</dd>
            <dt>Type<span class="bt">BT-3</span></dt><dd>{{ $en['type_code'] }} — {{ $types[$en['type_code']] ?? '?' }}</dd>
            <dt>Devise<span class="bt">BT-5</span></dt><dd>{{ $en['currency_code'] }}@if (! empty($en['vat_accounting_currency_code'])) (TVA en {{ $en['vat_accounting_currency_code'] }}, BT-6)@endif</dd>
            <dt>Cadre de facturation<span class="bt">BT-23</span></dt><dd>{{ $en['process_control']['business_process_type'] ?? '—' }} — {{ $frameworks[$en['process_control']['business_process_type'] ?? ''] ?? '' }}</dd>
            <dt>Profil<span class="bt">BT-24</span></dt><dd>{{ $en['process_control']['specification_identifier'] ?? '—' }}</dd>
            <dt>Flux</dt><dd>{{ $report['processing_rule'] }}{{ $report['processing_rule'] === 'B2B' ? ' — facture électronique au client via sa plateforme' : ' — e-reporting de transaction (client non assujetti ou étranger)' }}</dd>
            @if (! empty($en['buyer_reference']))<dt>Référence acheteur<span class="bt">BT-10</span></dt><dd>{{ $en['buyer_reference'] }}</dd>@endif
            @if (! empty($en['purchase_order_reference']))<dt>Bon de commande<span class="bt">BT-13</span></dt><dd>{{ $en['purchase_order_reference'] }}</dd>@endif
            @foreach ($en['preceding_invoice_references'] ?? [] as $ref)
                <dt>Facture d'origine<span class="bt">BT-25/26</span></dt><dd>{{ $ref['reference'] }} du {{ $date($ref['issue_date'] ?? null) }}</dd>
            @endforeach
            @if ($report['directory'])<dt>Annuaire (client)</dt><dd>{{ is_array($report['directory']) ? json_encode($report['directory'], JSON_UNESCAPED_UNICODE) : $report['directory'] }}</dd>@endif
        </dl>
    </div>

    <div class="grid">
        <div class="card"><h2>Vendeur <span class="bt">BG-4</span></h2><dl>@foreach ($party($en['seller']) as $k => $v)<dt>{{ $k }}</dt><dd>{{ $v }}</dd>@endforeach</dl></div>
        <div class="card"><h2>Client <span class="bt">BG-7</span></h2><dl>@foreach ($party($en['buyer']) as $k => $v)<dt>{{ $k }}</dt><dd>{{ $v }}</dd>@endforeach</dl></div>
    </div>

    <div class="card">
        <h2>Lignes <span class="bt">BG-25</span></h2>
        <table>
            <tr><th>#</th><th>Désignation</th><th class="n">Qté</th><th class="n">Prix unitaire HT</th><th>TVA</th><th class="n">Montant HT</th></tr>
            @foreach ($en['lines'] ?? [] as $line)
                <tr>
                    <td>{{ $line['identifier'] }}</td>
                    <td><strong>{{ $line['item_information']['name'] ?? '' }}</strong>@if (! empty($line['item_information']['description']))<br>{{ $line['item_information']['description'] }}@endif</td>
                    <td class="n">{{ rtrim(rtrim((string) $line['invoiced_quantity'], '0'), '.') ?: $line['invoiced_quantity'] }} {{ ($line['invoiced_quantity_code'] ?? 'C62') === 'C62' ? '' : $line['invoiced_quantity_code'] }}</td>
                    <td class="n">{{ $money($line['price_details']['item_net_price'] ?? 0) }}</td>
                    <td>{{ $line['vat_information']['invoiced_item_vat_category_code'] ?? '' }} {{ (float) ($line['vat_information']['invoiced_item_vat_rate'] ?? 0) }} %</td>
                    <td class="n">{{ $money($line['net_amount']) }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="grid">
        <div class="card">
            <h2>TVA <span class="bt">BG-23</span></h2>
            <table>
                <tr><th>Catégorie</th><th class="n">Base</th><th class="n">TVA</th><th>Exonération</th></tr>
                @foreach ($en['vat_break_down'] ?? [] as $vat)
                    <tr><td>{{ $vat['vat_category_code'] }} {{ (float) $vat['vat_category_rate'] }} %</td><td class="n">{{ $money($vat['vat_category_taxable_amount']) }}</td><td class="n">{{ $money($vat['vat_category_tax_amount']) }}</td>
                        <td>{{ $vat['vat_exemption_reason'] ?? '' }}@if (! empty($vat['vat_exemption_reason_code']))<br><span class="bt">{{ $vat['vat_exemption_reason_code'] }}</span>@endif</td></tr>
                @endforeach
            </table>
        </div>
        <div class="card">
            <h2>Totaux <span class="bt">BG-22</span></h2>
            <table>
                <tr><td>Total des lignes HT<span class="bt">BT-106</span></td><td class="n">{{ $money($en['totals']['sum_invoice_lines_amount'] ?? 0) }}</td></tr>
                @if ((float) ($en['totals']['sum_allowances_amount'] ?? 0))<tr><td>Remises<span class="bt">BT-107</span></td><td class="n">{{ $money($en['totals']['sum_allowances_amount']) }}</td></tr>@endif
                <tr><td>Total HT<span class="bt">BT-109</span></td><td class="n">{{ $money($en['totals']['total_without_vat'] ?? 0) }}</td></tr>
                <tr><td>TVA<span class="bt">BT-110</span></td><td class="n">{{ $money($en['totals']['total_vat_amount']['value'] ?? 0) }}</td></tr>
                <tr class="tot"><td>Total TTC<span class="bt">BT-112</span></td><td class="n">{{ $money($en['totals']['total_with_vat'] ?? 0) }}</td></tr>
                @if ((float) ($en['totals']['paid_amount'] ?? 0))<tr><td>Déjà payé<span class="bt">BT-113</span></td><td class="n">{{ $money($en['totals']['paid_amount']) }}</td></tr>@endif
                <tr class="tot"><td>Net à payer<span class="bt">BT-115</span></td><td class="n">{{ $money($en['totals']['amount_due_for_payment'] ?? 0) }}</td></tr>
            </table>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Paiement <span class="bt">BG-16</span></h2>
            <dl>
                <dt>Échéance<span class="bt">BT-9</span></dt><dd>{{ $date($en['payment_due_date'] ?? null) }}</dd>
                @if (! empty($en['payment_terms']))<dt>Conditions<span class="bt">BT-20</span></dt><dd>{{ $en['payment_terms'] }}</dd>@endif
                <dt>Moyen<span class="bt">BT-81</span></dt><dd>{{ ['30' => 'Virement', '58' => 'Virement SEPA', '48' => 'Carte', '10' => 'Espèces', '20' => 'Chèque'][$en['payment_instructions']['payment_means_type_code'] ?? ''] ?? ($en['payment_instructions']['payment_means_type_code'] ?? '—') }}</dd>
                @foreach ($en['payment_instructions']['credit_transfers'] ?? [] as $t)<dt>IBAN<span class="bt">BT-84</span></dt><dd>{{ $mask($t['payment_account_identifier']['value'] ?? '') }}</dd>@endforeach
                @if (! empty($en['payment_instructions']['remittance_information']))<dt>Référence de paiement<span class="bt">BT-83</span></dt><dd>{{ $en['payment_instructions']['remittance_information'] }}</dd>@endif
            </dl>
        </div>
        <div class="card">
            <h2>Mentions <span class="bt">BG-1</span></h2>
            <table>@foreach ($en['notes'] ?? [] as $note)<tr><td><span class="bt">{{ $note['subject_code'] ?? '' }}</span></td><td>{{ $note['note'] }}</td></tr>@endforeach</table>
        </div>
    </div>

    <div class="card">
        <h2>Cohérence PDF ⇔ données structurées</h2>
        @if ($report['pdf_checks'])
        <table>
            <tr><th>Donnée</th><th>Valeur structurée</th><th>Dans le PDF</th></tr>
            @foreach ($report['pdf_checks'] as $c)
                <tr><td>{{ $c['label'] }}</td><td>{{ $c['expected'] }}</td><td class="{{ $c['found'] ? 'yes' : ($c['blocking'] ? 'no' : 'soft') }}">{{ $c['found'] ? 'présente' : ($c['blocking'] ? 'ABSENTE (bloquant)' : 'absente (à vérifier)') }}</td></tr>
            @endforeach
        </table>
        @else
            <p>Contrôle non réalisé (voir les points d'attention).</p>
        @endif
    </div>
</main>
</body>
</html>
