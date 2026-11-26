<?php

namespace App\Services;

use App\Models\InboundDocument;
use App\Models\InvoiceEvent;
use App\Models\OutboundDocument;
use App\Models\SyncCursor;
use App\Services\SuperPdp\SuperPdpClient;
use Carbon\Carbon;

/** Lifecycle statuses (SUPER PDP has no webhooks: the event stream is polled with a cursor). */
class EventSync
{
    public function __construct(
        private readonly SuperPdpClient $superPdp,
        private readonly OutboundService $outbound,
        private readonly InboundService $inbound,
    ) {}

    public function run(): int
    {
        $count = 0;
        $after = (int) SyncCursor::get('superpdp.events.after', 0);

        do {
            $page = $this->superPdp->events($after);

            foreach ($page['data'] ?? [] as $event) {
                $this->apply($event);
                $after = max($after, (int) $event['id']);
                SyncCursor::put('superpdp.events.after', $after);
                $count++;
            }
        } while (($page['has_after'] ?? false) && ! empty($page['data']));

        return $count;
    }

    public function apply(array $event): void
    {
        $stored = InvoiceEvent::query()->firstOrCreate(
            ['superpdp_event_id' => $event['id']],
            [
                'superpdp_invoice_id' => $event['invoice_id'],
                'status_code' => $event['status_code'],
                'status_text' => $event['status_text'] ?? null,
                'details' => array_filter(['details' => $event['details'] ?? null, 'data' => $event['data'] ?? null]) ?: null,
                'event_created_at' => isset($event['created_at']) ? Carbon::parse($event['created_at']) : now(),
            ],
        );

        if (! $stored->wasRecentlyCreated) {
            return;
        }

        if ($document = OutboundDocument::query()->where('superpdp_id', $event['invoice_id'])->first()) {
            $this->outbound->applyEvent($document, $event);
        } elseif ($document = InboundDocument::query()->where('superpdp_id', $event['invoice_id'])->first()) {
            $this->inbound->applyEvent($document, $event);
        }
    }
}
