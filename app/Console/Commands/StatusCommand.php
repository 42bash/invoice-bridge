<?php

namespace App\Console\Commands;

use App\Models\ExchangeLog;
use App\Models\InboundDocument;
use App\Models\OutboundDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class StatusCommand extends Command
{
    protected $signature = 'einvoicing:status {--errors=10 : Nombre d\'erreurs récentes à afficher}';

    protected $description = 'Résumé des documents émis et reçus, dernières erreurs';

    public function handle(): int
    {
        $last = Cache::get('einvoicing.last_sync');
        $this->components->twoColumnDetail('Dernière synchronisation', $last['at'] ?? 'jamais');

        $this->newLine();
        $this->line('<options=bold>Factures émises</>');
        $this->table(['État', 'Nombre'], OutboundDocument::query()->selectRaw('state, count(*) as n')->groupBy('state')->get()
            ->map(fn (OutboundDocument $r) => [OutboundDocument::LABELS[$r->state] ?? $r->state, $r->getAttribute('n')])->all());

        $this->line('<options=bold>Factures reçues</>');
        $this->table(['État', 'Nombre'], InboundDocument::query()->selectRaw('state, count(*) as n')->groupBy('state')->get()
            ->map(fn (InboundDocument $r) => [InboundDocument::LABELS[$r->state] ?? $r->state, $r->getAttribute('n')])->all());

        $errors = ExchangeLog::query()->whereIn('level', ['warning', 'error'])->latest('id')->limit((int) $this->option('errors'))->get();

        if ($errors->isNotEmpty()) {
            $this->line('<options=bold>Dernières alertes</>');
            $this->table(['Date', 'Niveau', 'Action', 'Message'], $errors->map(fn ($e) => [
                $e->created_at->timezone('Europe/Paris')->format('d/m H:i'), $e->level, "{$e->channel}.{$e->action}", mb_strimwidth($e->message, 0, 110, '…'),
            ])->all());
        }

        return self::SUCCESS;
    }
}
