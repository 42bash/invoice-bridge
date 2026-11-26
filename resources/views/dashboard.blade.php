@extends('layout')

@php
    use App\Models\OutboundDocument as Out;
    use App\Models\InboundDocument as In;
    $sum = fn ($counts, $states) => collect($states)->sum(fn ($s) => $counts[$s] ?? 0);
@endphp

@section('content')
<h1>Tableau de bord</h1>

<div class="cards">
    <div class="card"><div class="n">{{ $sum($outboundCounts, [Out::PENDING, Out::READY, Out::SUBMITTING]) }}</div><div class="l">Émises en attente</div></div>
    <div class="card"><div class="n">{{ $sum($outboundCounts, [Out::SUBMITTED, Out::DELIVERED]) }}</div><div class="l">Émises transmises</div></div>
    <div class="card"><div class="n err">{{ $sum($outboundCounts, [Out::INVALID, Out::REJECTED, Out::REFUSED, Out::ERROR]) }}</div><div class="l">Émises à corriger</div></div>
    <div class="card"><div class="n">{{ $sum($inboundCounts, [In::RECEIVED, In::IMPORTED, In::NEEDS_REVIEW, In::DUPLICATE, In::ERROR, In::IGNORED]) }}</div><div class="l">Factures reçues</div></div>
    <div class="card"><div class="n warn">{{ $sum($inboundCounts, [In::RECEIVED, In::NEEDS_REVIEW, In::ERROR]) }}</div><div class="l">Reçues à traiter</div></div>
    <div class="card"><div class="n">{{ number_format($cost, 2, ',', ' ') }} € HT</div><div class="l">Coût SUPER PDP estimé {{ now()->year }} ({{ $volume }} factures)</div></div>
</div>

<div class="box" style="margin-top:14px">
    <dl>
        <dt>Connexion SUPER PDP</dt><dd>{{ $configured ? 'identifiants renseignés ('.$environment.')' : 'identifiants non renseignés' }}</dd>
        <dt>Envoi automatique</dt><dd>{{ config('einvoicing.auto_send') ? 'oui' : 'non (sur demande : champ « Facture électronique » = « '.config('einvoicing.invoice_ninja.action_send').' »)' }}</dd>
        <dt>Import automatique des reçues</dt><dd>{{ config('einvoicing.auto_receive') ? 'oui' : 'non (import manuel)' }}</dd>
        <dt>Dernière synchronisation</dt><dd>{{ isset($lastSync['at']) ? \Carbon\Carbon::parse($lastSync['at'])->timezone('Europe/Paris')->format('d/m/Y H:i') : 'jamais' }}</dd>
    </dl>
    <form method="post" action="{{ route('sync') }}" style="margin-top:10px">@csrf<button class="secondary">Synchroniser maintenant</button></form>
</div>

@if ($alerts->isNotEmpty())
<h2>Alertes récentes</h2>
<table>
    <tr><th>Date</th><th>Action</th><th>Message</th></tr>
    @foreach ($alerts as $log)
    <tr>
        <td class="muted">{{ $log->created_at?->timezone('Europe/Paris')->format('d/m H:i') }}</td>
        <td><span class="badge s-{{ $log->level === 'error' ? 'error' : 'pending' }}">{{ $log->channel }}.{{ $log->action }}</span></td>
        <td>{{ \Illuminate\Support\Str::limit($log->message, 220) }}</td>
    </tr>
    @endforeach
</table>
@endif

<h2>Factures et avoirs émis</h2>
<table>
    <tr><th>Numéro</th><th>Date</th><th>Client</th><th>Cadre</th><th class="num">Total</th><th>État</th><th>Dernier statut</th><th></th></tr>
    @forelse ($outbound as $doc)
    <tr>
        <td>{{ $doc->number }} @if($doc->entity_type === 'credit')<span class="muted">(avoir)</span>@endif</td>
        <td>{{ $doc->issue_date?->format('d/m/Y') }}</td>
        <td>{{ $doc->client_name }}</td>
        <td>{{ $doc->processing_rule }}</td>
        <td class="num">{{ number_format((float) $doc->total, 2, ',', ' ') }}</td>
        <td><span class="badge s-{{ $doc->state }}">{{ $doc->label() }}</span></td>
        <td>{{ $doc->last_status_code }} {{ $doc->last_status_text }}</td>
        <td><a href="{{ route('outbound.show', $doc) }}">Détail</a></td>
    </tr>
    @empty
    <tr><td colspan="8" class="muted">Aucun document suivi pour l'instant.</td></tr>
    @endforelse
</table>

<h2>Factures fournisseurs reçues</h2>
<table>
    <tr><th>Reçue le</th><th>Fournisseur</th><th>Numéro</th><th>Date</th><th class="num">TTC</th><th>État</th><th>Dernier statut</th><th></th></tr>
    @forelse ($inbound as $doc)
    <tr>
        <td>{{ $doc->received_at?->timezone('Europe/Paris')->format('d/m/Y') }}</td>
        <td>{{ $doc->seller_name }}</td>
        <td>{{ $doc->number }}</td>
        <td>{{ $doc->issue_date?->format('d/m/Y') }}</td>
        <td class="num">{{ number_format((float) $doc->total_with_vat, 2, ',', ' ') }} {{ $doc->currency }}</td>
        <td><span class="badge s-{{ $doc->state }}">{{ $doc->label() }}</span></td>
        <td>{{ $doc->last_status_code }} {{ $doc->last_status_text }}</td>
        <td><a href="{{ route('inbound.show', $doc) }}">Détail</a></td>
    </tr>
    @empty
    <tr><td colspan="8" class="muted">Aucune facture reçue.</td></tr>
    @endforelse
</table>
@endsection
