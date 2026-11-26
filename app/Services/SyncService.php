<?php

namespace App\Services;

use App\Models\InboundDocument;
use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\SuperPdp\SuperPdpClient;
use App\Support\Journal;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One synchronisation pass. Steps are independent: a failure in one is logged
 * and the others still run; cursors make the next pass resume where it stopped.
 */
class SyncService
{
    /** @var list<array{step: string, error: Throwable}> */
    private array $failures = [];

    public function __construct(
        private readonly SuperPdpClient $superPdp,
        private readonly InvoiceNinjaClient $invoiceNinja,
        private readonly InboundService $inbound,
        private readonly OutboundService $outbound,
        private readonly EventSync $events,
        private readonly Supervisor $supervisor,
    ) {}

    /** @return array<string, mixed>|null null when another pass is already running */
    public function run(bool $fullDiscovery = false): ?array
    {
        $lock = Cache::lock('einvoicing-sync', 1800);

        if (! $lock->get()) {
            return null;
        }

        $report = [];
        $this->failures = [];
        $lastInbound = (int) InboundDocument::query()->max('id');

        try {
            if ($this->superPdp->configured()) {
                $report['received'] = $this->step('inbound', 'poll', fn () => $this->inbound->poll());
                $report['events'] = $this->step('sync', 'events', fn () => $this->events->run());
            }

            if ($this->invoiceNinja->configured()) {
                $report['imported'] = $this->step('inbound', 'import', fn () => $this->inbound->importPending());
                $report['discovered'] = $this->step('outbound', 'discover', fn () => $this->outbound->discover($fullDiscovery));
                $report['outbound'] = $this->step('outbound', 'process', fn () => $this->outbound->processQueue());
                $report['payments'] = $this->step('outbound', 'payments', fn () => $this->outbound->reportPayments());
            }

            Cache::forever('einvoicing.last_sync', ['at' => now()->toIso8601String(), 'report' => $report]);

            $this->alert(fn () => $this->supervisor->received(InboundDocument::query()->where('id', '>', $lastInbound)->orderBy('id')->get()));
            $this->alert(fn () => $this->supervisor->runFinished($this->failures));

            return $report;
        } finally {
            $lock->release();
        }
    }

    private function step(string $channel, string $action, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Journal::exception($channel, "{$action}_failed", $e);
            $this->failures[] = ['step' => "{$channel}/{$action}", 'error' => $e];

            return 'erreur : '.Journal::mask($e->getMessage());
        }
    }

    /** An alerting problem must never break the synchronisation itself. */
    private function alert(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Journal::exception('notify', 'supervisor_failed', $e);
        }
    }
}
