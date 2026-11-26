<?php

namespace App\Console\Commands;

use App\Models\OutboundDocument;
use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\Mapping\MappingException;
use App\Services\OutboundService;
use Illuminate\Console\Command;

class PreviewCommand extends Command
{
    protected $signature = 'einvoicing:preview
        {number : Numéro de la facture (ou de l\'avoir) dans Invoice Ninja}
        {--credit : Il s\'agit d\'un avoir}
        {--deposit : Facture d\'acompte (type 386)}
        {--offline : Ne pas interroger l\'annuaire public pour le SIREN du client}';

    protected $description = 'Aperçu local de la facture électronique (données EN16931, contrôles, HTML) : rien n\'est transmis ni enregistré';

    public function handle(InvoiceNinjaClient $invoiceNinja, OutboundService $outbound): int
    {
        $kind = $this->option('credit') ? 'credit' : 'invoice';
        $number = (string) $this->argument('number');
        $doc = $kind === 'credit' ? $invoiceNinja->findCreditByNumber($number) : $invoiceNinja->findInvoiceByNumber($number);

        if (! $doc) {
            $this->components->error("Document {$number} introuvable dans Invoice Ninja.");

            return self::FAILURE;
        }

        // Never saved: no state, no write-back to Invoice Ninja.
        $document = new OutboundDocument([
            'entity_type' => $kind,
            'in_id' => $doc['id'],
            'number' => $doc['number'],
            'deposit' => (bool) $this->option('deposit'),
            'external_id' => OutboundDocument::externalIdFor($kind, $doc['id']),
        ]);

        try {
            $report = $outbound->preview($document, directory: ! $this->option('offline'));
        } catch (MappingException $e) {
            $this->components->error('Données incomplètes : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Environnement', (string) $report['environment']);
        $this->components->twoColumnDetail('Cadre', $report['processing_rule'].' — type '.$report['type_code']);
        $this->components->twoColumnDetail('Total TTC', (string) $report['mapped']['total_with_vat']);
        $this->components->twoColumnDetail('Annuaire (client)', is_array($report['directory']) ? json_encode($report['directory'], JSON_UNESCAPED_UNICODE) : (string) ($report['directory'] ?? 'non interrogé'));

        foreach ($report['pdf_checks'] as $check) {
            $this->components->twoColumnDetail('PDF ⇔ XML : '.$check['label'], $check['found'] ? '<fg=green>présente</>' : ($check['blocking'] ? '<fg=red>ABSENTE</>' : '<fg=yellow>absente</>'));
        }

        foreach ($report['files'] as $label => $file) {
            $this->components->twoColumnDetail("Fichier {$label}", $file['path']);
        }

        foreach ($report['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        foreach ($report['errors'] as $error) {
            $this->components->error($error);
        }

        $report['ok']
            ? $this->components->info('Aucune anomalie bloquante. Rien n\'a été transmis à SUPER PDP.')
            : $this->components->error('Anomalies bloquantes. Rien n\'a été transmis à SUPER PDP.');

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
