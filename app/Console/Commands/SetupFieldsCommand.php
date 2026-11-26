<?php

namespace App\Console\Commands;

use App\Services\InvoiceNinja\InvoiceNinjaClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * The connector token has no admin rights, so it cannot change company
 * settings: this command prints the labels to set once in Invoice Ninja
 * (Paramètres › Champs personnalisés) and checks they are in place.
 */
class SetupFieldsCommand extends Command
{
    protected $signature = 'einvoicing:setup-fields
        {--json : Afficher le JSON custom_fields attendu}
        {--apply : Écrire les libellés via l\'API Invoice Ninja (jeton administrateur requis)}';

    protected $description = 'Vérifie les libellés des champs personnalisés utilisés dans Invoice Ninja';

    public static function expected(): array
    {
        $fields = config('einvoicing.invoice_ninja.fields');
        $expenseFields = config('einvoicing.invoice_ninja.expense_fields');
        $actions = implode(',', [
            config('einvoicing.invoice_ninja.action_send'),
            config('einvoicing.invoice_ninja.action_send_deposit'),
            config('einvoicing.invoice_ninja.action_skip'),
            config('einvoicing.invoice_ninja.action_retry'),
        ]);
        $slot = fn (string $entity, string $column) => $entity.substr($column, -1);

        return [
            $slot('invoice', $fields['action']) => "Facture électronique|{$actions}",
            $slot('invoice', $fields['status']) => 'Statut e-facture',
            $slot('invoice', $fields['reference']) => 'Réf. SuperPDP',
            $slot('invoice', $fields['detail']) => 'Détail e-facture',
            $slot('credit', $fields['action']) => "Facture électronique|{$actions}",
            $slot('credit', $fields['status']) => 'Statut e-facture',
            $slot('credit', $fields['reference']) => 'Réf. SuperPDP',
            $slot('credit', $fields['detail']) => 'Détail e-facture',
            $slot('expense', $expenseFields['reference']) => 'Réf. SuperPDP',
            $slot('expense', $expenseFields['status']) => 'Statut e-facture',
        ];
    }

    public function handle(InvoiceNinjaClient $invoiceNinja): int
    {
        $expected = self::expected();

        if ($this->option('json')) {
            $this->line(json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $current = $invoiceNinja->company()['custom_fields'] ?? [];
        $missing = 0;

        foreach ($expected as $key => $label) {
            $actual = $current[$key] ?? null;
            $state = match (true) {
                $actual === $label => '<fg=green>OK</>',
                blank($actual) => '<fg=yellow>à créer</>',
                default => "<fg=red>occupé par « {$actual} »</>",
            };
            $missing += $actual === $label ? 0 : 1;
            $this->components->twoColumnDetail("{$key} = {$label}", $state);
        }

        if ($this->option('apply')) {
            $company = $invoiceNinja->company();
            $id = (string) ($company['id'] ?? '');

            if ($id === '') {
                $this->components->error('Société Invoice Ninja introuvable : --apply impossible.');

                return self::FAILURE;
            }

            try {
                $invoiceNinja->update('companies', $id, [
                    'custom_fields' => array_merge((array) ($company['custom_fields'] ?? []), $expected),
                ]);
                $this->components->info('Libellés écrits dans Invoice Ninja.');
                $missing = 0;
            } catch (Throwable $e) {
                $this->components->error('Écriture API refusée (le jeton doit être administrateur) : '.$e->getMessage());

                return self::FAILURE;
            }
        } elseif ($missing) {
            $this->newLine();
            $this->line('Dans Invoice Ninja : Paramètres › Champs personnalisés › Factures / Avoirs / Dépenses, renseignez les libellés ci-dessus (le texte après « | » correspond aux choix de la liste déroulante). Avec un jeton administrateur : einvoicing:setup-fields --apply.');
        }

        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
