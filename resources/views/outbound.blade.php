@extends('layout')

@section('title', $document->number)

@section('content')
<h1>{{ $document->entity_type === 'credit' ? 'Avoir' : 'Facture' }} {{ $document->number }} <span class="badge s-{{ $document->state }}">{{ $document->label() }}</span></h1>

<div class="box">
    <dl>
        <dt>Client</dt><dd>{{ $document->client_name }} @if($document->client_siren)<span class="muted">SIREN {{ $document->client_siren }}</span>@endif</dd>
        <dt>Date</dt><dd>{{ $document->issue_date?->format('d/m/Y') }}</dd>
        <dt>Total TTC</dt><dd>{{ number_format((float) $document->total, 2, ',', ' ') }} {{ $document->currency }}</dd>
        <dt>Cadre / type</dt><dd>{{ $document->processing_rule ?? '—' }} / {{ $document->type_code ?? '—' }} @if($document->deposit)(acompte)@endif</dd>
        <dt>Annuaire</dt><dd>{{ $document->directory_status ?? '—' }}</dd>
        <dt>Référence SUPER PDP</dt><dd>{{ $document->superpdp_id ? '#'.$document->superpdp_id : '—' }} <span class="muted">{{ $document->external_id }}</span></dd>
        <dt>Dernier statut</dt><dd>{{ $document->last_status_code }} {{ $document->last_status_text }} {{ $document->last_status_at?->timezone('Europe/Paris')->format('d/m/Y H:i') }}</dd>
        @if ($document->rejection_reason)<dt>Motif</dt><dd class="err">{{ $document->rejection_reason }}</dd>@endif
        <dt>Encaissement déclaré</dt><dd>{{ $document->payment_reported_at?->timezone('Europe/Paris')->format('d/m/Y H:i') ?? 'non' }}</dd>
    </dl>
</div>

@if ($document->errors)
<div class="box"><strong class="err">À corriger dans Invoice Ninja</strong><ul>@foreach ((array) $document->errors as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
@if ($document->warnings)
<div class="box"><strong class="warn">Points d'attention</strong><ul>@foreach ((array) $document->warnings as $w)<li>{{ $w }}</li>@endforeach</ul></div>
@endif

<div class="box">
    <strong>Fichiers archivés</strong>
    <ul>
        @foreach ((array) $document->files as $kind => $file)
        <li><a href="{{ route('file', ['outbound', $document->id, $kind]) }}">{{ $file['name'] }}</a> <span class="muted">{{ number_format($file['size'] / 1024, 1, ',', ' ') }} Ko — SHA-256 {{ substr($file['sha256'], 0, 16) }}…</span></li>
        @endforeach
    </ul>

    @unless ($document->superpdp_id)
    <form method="post" action="{{ route('outbound.prepare', $document) }}" class="inline">@csrf<button class="secondary">Générer et valider la Factur-X</button></form>
    @endunless

    @if (! $document->superpdp_id && $document->state === \App\Models\OutboundDocument::READY && config('einvoicing.enabled'))
    <form method="post" action="{{ route('outbound.send', $document) }}" style="margin-top:12px">
        @csrf
        <label><input type="checkbox" name="confirm" value="1" required> Je confirme le dépôt de cette facture sur SUPER PDP (environnement <strong>{{ config('einvoicing.superpdp.environment') }}</strong>).</label>
        <button>Transmettre</button>
    </form>
    @endif

    @if ($document->superpdp_id && ! $document->payment_reported_at && $document->entity_type === 'invoice' && config('einvoicing.enabled'))
    <form method="post" action="{{ route('outbound.payment', $document) }}" style="margin-top:12px">
        @csrf
        <label><input type="checkbox" name="confirm" value="1" required> La facture est intégralement encaissée : transmettre le statut « Encaissée » (fr:212).</label>
        <button class="secondary">Déclarer l'encaissement</button>
    </form>
    @endif
</div>

@include('partials.events', ['events' => $events, 'logs' => $logs])
@endsection
