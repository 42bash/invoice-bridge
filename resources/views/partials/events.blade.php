<h2>Statuts SUPER PDP</h2>
<table>
    <tr><th>Date</th><th>Statut</th><th>Détail</th></tr>
    @forelse ($events as $event)
    <tr>
        <td class="muted">{{ $event->event_created_at?->timezone('Europe/Paris')->format('d/m/Y H:i') }}</td>
        <td>{{ $event->status_code }} — {{ \App\Models\InvoiceEvent::labelFor($event->status_code, $event->status_text) }}</td>
        <td>{{ \App\Models\InvoiceEvent::reasonFrom(['details' => $event->details['details'] ?? [], 'data' => $event->details['data'] ?? []]) }}</td>
    </tr>
    @empty
    <tr><td colspan="3" class="muted">Aucun statut reçu.</td></tr>
    @endforelse
</table>

<h2>Journal</h2>
<table>
    <tr><th>Date</th><th>Niveau</th><th>Action</th><th>Message</th></tr>
    @forelse ($logs as $log)
    <tr>
        <td class="muted">{{ $log->created_at?->timezone('Europe/Paris')->format('d/m/Y H:i:s') }}</td>
        <td>{{ $log->level }}</td>
        <td>{{ $log->channel }}.{{ $log->action }}</td>
        <td>{{ $log->message }}</td>
    </tr>
    @empty
    <tr><td colspan="4" class="muted">Aucune entrée.</td></tr>
    @endforelse
</table>
