<?php

namespace App\Console\Commands;

use App\Services\SandboxPeer;
use Illuminate\Console\Command;
use Throwable;

class SandboxPeerCommand extends Command
{
    protected $signature = 'einvoicing:sandbox-peer
        {action : whoami | send | resend | inbox | respond}
        {arg? : numéro (resend) ou id SuperPDP côté partenaire (respond)}
        {status? : statut pour respond (fr:204, fr:205, fr:207, fr:210, fr:212…)}
        {--number= : numéro de la facture de test (send)}
        {--credit= : envoie un avoir corrigeant ce numéro (send)}
        {--reason= : code motif (respond)}
        {--note= : commentaire libre (respond)}';

    protected $description = 'Bac à sable uniquement : fait agir la seconde entreprise de test (Tricatel) envers la vôtre';

    public function handle(SandboxPeer $peer): int
    {
        try {
            return match ($this->argument('action')) {
                'whoami' => $this->whoami($peer),
                'send' => $this->send($peer),
                'resend' => $this->resend($peer),
                'inbox' => $this->inbox($peer),
                'respond' => $this->respond($peer),
                default => $this->fail('Action inconnue.'),
            };
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function whoami(SandboxPeer $peer): int
    {
        ['me' => $me, 'peer' => $other] = $peer->guard();
        $this->components->twoColumnDetail('Votre entreprise de test', "{$me['formal_name']} — SIREN {$me['number']} ({$me['env']})");
        $this->components->twoColumnDetail('Partenaire de test', "{$other['formal_name']} — SIREN {$other['number']} ({$other['env']})");

        return self::SUCCESS;
    }

    private function send(SandboxPeer $peer): int
    {
        $result = $peer->sendToMe($this->option('number'), $this->option('credit'));
        $valid = $result['validation']['is_valid'] ?? null;
        $this->components->info("Envoyée : {$result['number']} (SuperPDP #{$result['superpdp_id']}, validation : ".($valid ? 'OK' : 'à vérifier').').');

        return self::SUCCESS;
    }

    private function resend(SandboxPeer $peer): int
    {
        $sent = $peer->resend((string) $this->argument('arg'));
        $this->components->info('Renvoyée à l\'identique : SuperPDP #'.($sent['id'] ?? '?'));

        return self::SUCCESS;
    }

    private function inbox(SandboxPeer $peer): int
    {
        $rows = array_map(fn (array $i) => [
            $i['id'],
            $i['external_id'] ?? '',
            $i['en_invoice']['number'] ?? '',
            collect($i['events'] ?? [])->last()['status_code'] ?? '',
            $i['created_at'] ?? '',
        ], $peer->inbox());

        $this->table(['Id (côté partenaire)', 'external_id', 'Numéro', 'Dernier statut', 'Reçue le'], $rows);

        return self::SUCCESS;
    }

    private function respond(SandboxPeer $peer): int
    {
        $event = $peer->respond((int) $this->argument('arg'), (string) $this->argument('status'), $this->option('reason'), $this->option('note'));
        $this->components->info('Statut transmis (événement #'.($event['id'] ?? '?').').');

        return self::SUCCESS;
    }
}
