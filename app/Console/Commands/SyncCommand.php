<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'einvoicing:sync
        {--full : Relire tous les documents Invoice Ninja, pas seulement les modifiés}
        {--force : Exécuter même si EINVOICING_ENABLED=false (lecture et préparation seulement)}';

    protected $description = 'Réception, statuts, préparation et envoi (selon les interrupteurs)';

    public function handle(SyncService $sync): int
    {
        if (! config('einvoicing.enabled') && ! $this->option('force')) {
            $this->components->info('Connecteur désactivé (EINVOICING_ENABLED=false) : rien à faire.');

            return self::SUCCESS;
        }

        $report = $sync->run((bool) $this->option('full'));

        if ($report === null) {
            $this->components->warn('Une synchronisation est déjà en cours.');

            return self::SUCCESS;
        }

        foreach ($report as $step => $result) {
            $this->components->twoColumnDetail($step, is_array($result) ? json_encode($result) : (string) $result);
        }

        return self::SUCCESS;
    }
}
