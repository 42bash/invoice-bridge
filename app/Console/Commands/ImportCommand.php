<?php

namespace App\Console\Commands;

use App\Models\InboundDocument;
use App\Services\InboundService;
use Illuminate\Console\Command;

class ImportCommand extends Command
{
    protected $signature = 'einvoicing:import
        {id? : Identifiant SUPER PDP de la facture reçue (sinon : toutes les factures en attente)}
        {--poll : Interroger SUPER PDP avant l\'import}
        {--force : Importer même un doublon détecté}';

    protected $description = 'Importe les factures fournisseurs reçues dans Invoice Ninja (dépenses)';

    public function handle(InboundService $inbound): int
    {
        if ($this->option('poll')) {
            $this->components->info($inbound->poll().' nouvelle(s) facture(s) reçue(s).');
        }

        $documents = $this->argument('id')
            ? InboundDocument::query()->where('superpdp_id', $this->argument('id'))->get()
            : InboundDocument::query()->whereIn('state', [InboundDocument::RECEIVED, InboundDocument::ERROR, InboundDocument::NEEDS_REVIEW])->get();

        foreach ($documents as $document) {
            $ok = $inbound->import($document, (bool) $this->option('force'));
            $document->refresh();
            $this->components->twoColumnDetail("{$document->seller_name} {$document->number}", $ok ? '<fg=green>importée</>' : '<fg=yellow>'.$document->label().'</> '.implode(' | ', (array) $document->errors));
        }

        return self::SUCCESS;
    }
}
