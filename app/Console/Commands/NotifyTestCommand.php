<?php

namespace App\Console\Commands;

use App\Services\Notifier;
use Illuminate\Console\Command;

class NotifyTestCommand extends Command
{
    protected $signature = 'einvoicing:notify-test';

    protected $description = 'Envoie une notification de test sur les canaux configurés (e-mail, webhook)';

    public function handle(Notifier $notifier): int
    {
        $channels = $notifier->channels();

        $notifier->notify('test', 'Notification de test', "Ce message confirme que les alertes du connecteur de facturation électronique vous parviennent.\n\nAlertes actives : "
            .collect(explode(',', (string) config('einvoicing.notify.events')))->map(fn ($k) => Notifier::KINDS[trim($k)] ?? trim($k))->implode(', ').'.');

        $this->components->twoColumnDetail('Canaux', $channels ? implode(', ', $channels) : 'tableau de bord uniquement (aucun e-mail ni webhook configuré)');
        $this->components->info('Notification de test enregistrée dans le journal.');

        return self::SUCCESS;
    }
}
