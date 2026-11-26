<?php

namespace App\Console\Commands;

use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\Notifier;
use App\Services\OutboundService;
use App\Services\SellerResolver;
use App\Services\SuperPdp\SuperPdpClient;
use App\Support\Journal;
use Illuminate\Console\Command;
use Throwable;

class CheckCommand extends Command
{
    protected $signature = 'einvoicing:check';

    protected $description = 'Vérifie la configuration et les connexions (aucune écriture)';

    public function handle(InvoiceNinjaClient $invoiceNinja, SuperPdpClient $superPdp, SellerResolver $seller): int
    {
        $ok = true;

        $this->components->twoColumnDetail('<fg=gray>Interrupteurs</>', sprintf(
            'enabled=%s send_enabled=%s auto_send=%s auto_receive=%s report_payments=%s',
            json_encode(config('einvoicing.enabled')), json_encode(config('einvoicing.send_enabled')), json_encode(config('einvoicing.auto_send')),
            json_encode(config('einvoicing.auto_receive')), json_encode(config('einvoicing.report_payments')),
        ));

        foreach (['invoice_ninja.url' => 'INVOICE_NINJA_URL', 'superpdp.api_url' => 'SUPERPDP_API_URL'] as $key => $env) {
            $url = (string) config("einvoicing.{$key}");
            // TEST instance only: plain HTTP to a Docker service name, which never leaves the host.
            $internal = $key === 'invoice_ninja.url' && config('einvoicing.test_instance') && preg_match('#^http://[a-z0-9_-]+(:\d+)?$#i', $url);

            if ($internal) {
                $this->components->warn("{$env} en HTTP sur le réseau Docker interne (instance de test uniquement).");
            } elseif (! str_starts_with($url, 'https://')) {
                $this->components->error("{$env} doit être en HTTPS.");
                $ok = false;
            }
        }

        try {
            $company = $invoiceNinja->company();
            $this->components->twoColumnDetail('Invoice Ninja', '<fg=green>OK</> '.($company['settings']['name'] ?? ''));
            $labels = $company['custom_fields'] ?? [];

            foreach (['invoice1', 'invoice2', 'invoice3', 'invoice4', 'expense1', 'expense2'] as $field) {
                if (blank($labels[$field] ?? null)) {
                    $this->components->warn("Champ personnalisé « {$field} » sans libellé dans Invoice Ninja (lancer einvoicing:setup-fields).");
                }
            }

            // Invoices and credit notes share one numbering for SUPER PDP: "0001" cannot be both.
            $s = $company['settings'] ?? [];
            $invoicePattern = (string) ($s['invoice_number_pattern'] ?? '');
            $creditPattern = (string) ($s['credit_number_pattern'] ?? '');
            $distinct = ($s['shared_invoice_credit_counter'] ?? false) || ($creditPattern !== '' && $creditPattern !== $invoicePattern);
            $this->components->twoColumnDetail('  Numérotation factures / avoirs', $distinct
                ? '<fg=green>distincte</> '.($invoicePattern ?: '(défaut)').' / '.($creditPattern ?: 'compteur partagé')
                : '<fg=red>identique</> (un avoir « 0002 » serait refusé comme doublon de la facture « 0002 ») : définir un modèle de numéro d\'avoir, ex. AV-{$year}-{$counter}');
            $ok = $ok && $distinct;

            $lock = (string) ($s['lock_invoices'] ?? 'off');

            if (! in_array($lock, ['off', 'disabled', ''], true)) {
                $this->components->warn("Verrouillage des factures Invoice Ninja « {$lock} » : une facture verrouillée refuse la mise à jour des champs (HTTP 422). Le connecteur laisse les montants intacts et consigne le statut dans l'historique d'activité.");
            }
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('Invoice Ninja', '<fg=red>ÉCHEC</> '.Journal::mask($e->getMessage()));
            $ok = false;
        }

        if (! $superPdp->configured()) {
            $this->components->twoColumnDetail('SUPER PDP', '<fg=yellow>identifiants non renseignés</>');
        } else {
            try {
                $me = $superPdp->me(true);
                $superPdp->assertEnvironment();
                $this->components->twoColumnDetail('SUPER PDP', "<fg=green>OK</> {$me['formal_name']} — environnement {$me['env']}");

                foreach ($superPdp->ownDirectoryEntries() as $entry) {
                    $this->components->twoColumnDetail('  Annuaire', "{$entry['directory']} {$entry['identifier']} ({$entry['status']})");
                }

                $regime = (string) ($me['vat_regime'] ?? '');
                $consistent = $regime !== '' && (($regime === 'vat_exemption') === (bool) config('einvoicing.vat.franchise'));
                $this->components->twoColumnDetail('  Régime TVA SUPER PDP', $consistent
                    ? "<fg=green>{$regime}</>"
                    : '<fg=red>'.($regime ?: 'non renseigné').'</> (attendu : '.(config('einvoicing.vat.franchise') ? 'vat_exemption' : 'monthly, quarterly ou simplified').' ; requis pour l\'e-reporting)');
                $ok = $ok && $consistent;
            } catch (Throwable $e) {
                $this->components->twoColumnDetail('SUPER PDP', '<fg=red>ÉCHEC</> '.Journal::mask($e->getMessage()));
                $ok = false;
            }
        }

        try {
            $identity = $seller->resolve();

            foreach (['name', 'siren', 'electronic_address', 'address_line1', 'post_code', 'city', 'country'] as $field) {
                $value = $identity[$field] ?? null;
                $this->components->twoColumnDetail("  Vendeur {$field}", filled($value) ? (string) $value : '<fg=red>manquant</>');
                $ok = $ok && filled($value);
            }

            $bg4 = ['name' => $identity['name'] ?? '', 'postal_address' => ['address_line1' => $identity['address_line1'] ?? '', 'post_code' => $identity['post_code'] ?? '']];

            foreach (OutboundService::sellerWarnings($identity['pdf'] ?? [], $bg4) as $warning) {
                $this->components->warn($warning);
            }

            if (! config('einvoicing.vat.franchise_set')) {
                $this->components->warn('EINVOICING_VAT_FRANCHISE n\'est pas défini : le régime par défaut du produit est « assujetti ». Fixez true (franchise) ou false explicitement.');
            }

            $mail = app(Notifier::class)->channels();
            $this->components->twoColumnDetail('  Alertes', $mail === [] ? 'journal du tableau de bord (SMTP non configuré)' : implode(', ', $mail));

            $this->components->twoColumnDetail('  Régime TVA', config('einvoicing.vat.franchise') ? 'Exonéré : '.config('einvoicing.vat.exemption_code').' — '.config('einvoicing.vat.exemption_text') : 'Assujetti');
        } catch (Throwable $e) {
            $this->components->error('Identité vendeur : '.Journal::mask($e->getMessage()));
            $ok = false;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
