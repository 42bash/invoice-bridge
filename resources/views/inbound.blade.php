@extends('layout')

@section('title', $document->number)

@section('content')
<h1>{{ in_array($document->type_code, [381, 261, 262, 396, 502, 503]) ? 'Avoir' : 'Facture' }} fournisseur {{ $document->number }} <span class="badge s-{{ $document->state }}">{{ $document->label() }}</span></h1>

<div class="box">
    <dl>
        <dt>Fournisseur</dt><dd>{{ $document->seller_name }} <span class="muted">{{ $document->seller_siren ? 'SIREN '.$document->seller_siren : '' }} {{ $document->seller_vat }}</span></dd>
        <dt>Date / échéance</dt><dd>{{ $document->issue_date?->format('d/m/Y') }} / {{ $document->due_date?->format('d/m/Y') ?? '—' }}</dd>
        <dt>HT / TVA / TTC</dt><dd>{{ $document->total_without_vat }} / {{ $document->total_vat }} / <strong>{{ $document->total_with_vat }}</strong> {{ $document->currency }}</dd>
        @if ($document->amount_due !== null && (float) $document->amount_due !== (float) $document->total_with_vat)<dt>Net à payer</dt><dd>{{ $document->amount_due }}</dd>@endif
        <dt>Référence SUPER PDP</dt><dd>#{{ $document->superpdp_id }} — reçue le {{ $document->received_at?->timezone('Europe/Paris')->format('d/m/Y H:i') }}</dd>
        <dt>Dernier statut</dt><dd>{{ $document->last_status_code }} {{ $document->last_status_text }}</dd>
        <dt>Invoice Ninja</dt><dd>
            @if ($document->in_expense_id)
                <a href="{{ config('einvoicing.invoice_ninja.url') }}/expenses/{{ $document->in_expense_id }}/edit" target="_blank" rel="noopener">Dépense</a>
            @else
                non importée
            @endif
        </dd>
        @if ($document->duplicate_of_id)<dt>Doublon de</dt><dd><a href="{{ route('inbound.show', $document->duplicate_of_id) }}">document #{{ $document->duplicate_of_id }}</a></dd>@endif
    </dl>
</div>

@if ($document->errors)
<div class="box"><strong class="err">À vérifier</strong><ul>@foreach ((array) $document->errors as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="box">
    <strong>Lignes</strong>
    <table style="margin-top:8px">
        <tr><th>Désignation</th><th class="num">Quantité</th><th class="num">Montant HT</th><th class="num">TVA %</th></tr>
        @foreach ($document->summary['lines'] ?? [] as $line)
        <tr><td>{{ $line['name'] }}</td><td class="num">{{ $line['quantity'] }}</td><td class="num">{{ $line['net_amount'] }}</td><td class="num">{{ $line['vat_rate'] }}</td></tr>
        @endforeach
    </table>
    @foreach ($document->summary['notes'] ?? [] as $note)<p class="muted">{{ $note }}</p>@endforeach
</div>

<div class="box">
    <strong>Documents (originaux conservés sans modification)</strong>
    <ul>
        @foreach ((array) $document->files as $kind => $file)
        <li><a href="{{ route('file', ['inbound', $document->id, $kind]) }}">{{ $file['name'] }}</a> <span class="muted">{{ $kind === 'original' ? 'fichier original reçu' : ($kind === 'readable' ? 'version lisible' : 'données structurées') }} — SHA-256 {{ substr($file['sha256'], 0, 16) }}…</span></li>
        @endforeach
    </ul>

    @unless ($document->in_expense_id)
    <form method="post" action="{{ route('inbound.import', $document) }}" class="inline">@csrf<button>Importer dans Invoice Ninja</button></form>
    <form method="post" action="{{ route('inbound.ignore', $document) }}" class="inline" style="margin-left:12px">
        @csrf
        <input type="text" name="reason" placeholder="Motif (doublon, erreur…)" required maxlength="255">
        <button class="secondary">Écarter</button>
    </form>
    @endunless
</div>

@if (config('einvoicing.enabled'))
<div class="box">
    <strong>Répondre au fournisseur (statut de cycle de vie)</strong>
    <form method="post" action="{{ route('inbound.status', $document) }}" style="margin-top:8px">
        @csrf
        <select name="status" required>
            @foreach ($buyerStatuses as $code => $label)<option value="{{ $code }}">{{ $code }} — {{ $label }}</option>@endforeach
        </select>
        <input type="text" name="reason" placeholder="Code motif XP Z12-012 (obligatoire pour litige/refus)" maxlength="40" size="38">
        <br><textarea name="note" rows="2" cols="80" maxlength="1000" placeholder="Commentaire transmis au fournisseur (facultatif)" style="margin-top:6px"></textarea>
        <br><label><input type="checkbox" name="confirm" value="1" required> Je confirme l'envoi de ce statut, qui sera transmis au fournisseur{{ config('einvoicing.superpdp.environment') === 'production' ? ' et, selon le statut, à l\'administration' : '' }}.</label>
        <button class="secondary">Transmettre le statut</button>
    </form>
</div>
@endif

@include('partials.events', ['events' => $events, 'logs' => $logs])
@endsection
