<?php

namespace App\Console\Commands;

use App\Models\OutboundDocument;
use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\OutboundService;
use App\Services\SuperPdp\SuperPdpClient;
use Illuminate\Console\Command;

class SendCommand extends Command
{
    protected $signature = 'einvoicing:send
        {number : Numéro de la facture (ou de l\'avoir) dans Invoice Ninja}
        {--credit : Il s\'agit d\'un avoir}
        {--deposit : Facture d\'acompte (type 386)}
        {--dry-run : Générer et valider seulement, sans rien enregistrer ni transmettre}
        {--yes : Ne pas demander de confirmation avant le dépôt}';

    protected $description = 'Prépare, valide et (sauf --dry-run) dépose une facture sur SUPER PDP';

    public function handle(InvoiceNinjaClient $invoiceNinja, SuperPdpClient $superPdp, OutboundService $outbound): int
    {
        $kind = $this->option('credit') ? 'credit' : 'invoice';
        $number = (string) $this->argument('number');
        $doc = $kind === 'credit' ? $invoiceNinja->findCreditByNumber($number) : $invoiceNinja->findInvoiceByNumber($number);

        if (! $doc) {
            $this->components->error("Document {$number} introuvable dans Invoice Ninja.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $document = new OutboundDocument([
                'entity_type' => $kind,
                'in_id' => $doc['id'],
                'number' => $doc['number'],
                'deposit' => (bool) $this->option('deposit'),
                'external_id' => OutboundDocument::externalIdFor($kind, $doc['id']),
            ]);

            $result = $outbound->prepare($document, dryRun: true);
            $this->report($result);

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        if (! OutboundService::emissionEnabled()) {
            $this->components->error('Émission désactivée (EINVOICING_ENABLED / EINVOICING_SEND_ENABLED). Utilisez --dry-run pour tester.');

            return self::FAILURE;
        }

        $document = $outbound->upsert($kind, $doc, track: true);

        if ($document->superpdp_id) {
            $this->components->info("Déjà déposée sur SUPER PDP (#{$document->superpdp_id}, {$document->label()}).");

            return self::SUCCESS;
        }

        if (in_array((int) ($doc['status_id'] ?? 0), [1, 5, 6], true) || ($doc['is_deleted'] ?? false)) {
            $this->components->error('Seule une facture émise (envoyée, partiellement payée ou payée) peut être transmise.');

            return self::FAILURE;
        }

        $document->forceFill(['requested' => true, 'deposit' => (bool) $this->option('deposit') || $document->deposit, 'state' => OutboundDocument::PENDING])->save();
        $result = $outbound->prepare($document->refresh());
        $this->report($result);

        if (! $result['ok']) {
            return self::FAILURE;
        }

        $environment = $superPdp->environment();

        if (! $this->option('yes') && ! $this->confirm("Déposer {$document->number} sur SUPER PDP (environnement : {$environment}) ?", false)) {
            $this->components->info('Dépôt annulé ; la facture reste prête (état : prête à transmettre).');

            return self::SUCCESS;
        }

        $outbound->send($document->refresh());
        $document->refresh();

        if (! $document->superpdp_id) {
            $this->components->error('Dépôt non effectué : '.implode(' | ', (array) $document->errors));

            return self::FAILURE;
        }

        $this->components->info("Déposée : SUPER PDP #{$document->superpdp_id} ({$document->last_status_code} {$document->last_status_text}).");

        return self::SUCCESS;
    }

    private function report(array $result): void
    {
        if (isset($result['mapped'])) {
            $this->components->twoColumnDetail('Cadre', $result['mapped']['processing_rule'].' — type '.$result['mapped']['type_code']);
            $this->components->twoColumnDetail('Total TTC', (string) $result['mapped']['total_with_vat']);
        }

        foreach ($result['files'] ?? [] as $kind => $file) {
            $this->components->twoColumnDetail("Archive {$kind}", $file['path']);
        }

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->components->warn($warning);
        }

        foreach ($result['errors'] ?? [] as $error) {
            $this->components->error($error);
        }

        $result['ok'] ? $this->components->info('Factur-X valide (validateur SUPER PDP).') : $this->components->error('Factur-X non valide.');
    }
}
