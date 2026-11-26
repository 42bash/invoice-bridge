@extends('layout')

@section('title', 'Journal')

@section('content')
<h1>Journal des échanges</h1>
<p>
    <a href="{{ route('journal') }}">Tout</a> ·
    <a href="{{ route('journal', ['level' => 'warning']) }}">Avertissements</a> ·
    <a href="{{ route('journal', ['level' => 'error']) }}">Erreurs</a>
</p>
<table>
    <tr><th>Date</th><th>Niveau</th><th>Action</th><th>Document</th><th>Message</th></tr>
    @foreach ($logs as $log)
    <tr>
        <td class="muted">{{ $log->created_at?->timezone('Europe/Paris')->format('d/m/Y H:i:s') }}</td>
        <td>{{ $log->level }}</td>
        <td>{{ $log->channel }}.{{ $log->action }}</td>
        <td>
            @if ($log->document_type === 'outbound' && $log->document_id)<a href="{{ route('outbound.show', $log->document_id) }}">émise #{{ $log->document_id }}</a>
            @elseif ($log->document_type === 'inbound' && $log->document_id)<a href="{{ route('inbound.show', $log->document_id) }}">reçue #{{ $log->document_id }}</a>
            @endif
        </td>
        <td>{{ $log->message }}</td>
    </tr>
    @endforeach
</table>
<p>{{ $logs->links() }}</p>
@endsection
