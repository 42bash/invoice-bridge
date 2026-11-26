<?php

namespace App\Services;

use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\SuperPdp\SuperPdpClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Seller identity used in BG-4. Priority: explicit EINVOICING_SELLER_* values,
 * then the company verified by SUPER PDP (legal name, SIREN, registered
 * address, own directory entry), then the Invoice Ninja company settings.
 */
class SellerResolver
{
    public function __construct(
        private readonly SuperPdpClient $superPdp,
        private readonly InvoiceNinjaClient $invoiceNinja,
    ) {}

    public function resolve(): array
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(30), fn () => $this->build());
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /** Changing the seller configuration or the credentials invalidates the cached identity. */
    private function cacheKey(): string
    {
        return 'einvoicing.seller.'.md5(json_encode([config('einvoicing.seller'), config('einvoicing.superpdp.client_id'), config('einvoicing.superpdp.environment')]));
    }

    private function build(): array
    {
        $cfg = array_filter((array) config('einvoicing.seller'), fn ($v) => filled($v));
        $spdp = [];
        $in = [];

        if ($this->superPdp->configured()) {
            try {
                $me = $this->superPdp->me();
                $spdp = [
                    'name' => $me['formal_name'] ?? null,
                    'trading_name' => $me['trade_name'] ?? null,
                    'siren' => ($me['number_scheme'] ?? '') === 'fr_siren' || preg_match('/^\d{9}$/', (string) ($me['number'] ?? '')) ? ($me['number'] ?? null) : null,
                    'address_line1' => $me['address'] ?? null,
                    'post_code' => $me['postcode'] ?? null,
                    'city' => $me['city'] ?? null,
                    'country' => $me['country'] ?? null,
                    'electronic_address' => $this->ownAddress(),
                    'environment' => $me['env'] ?? null,
                ];
            } catch (Throwable) {
                // Falls back to configuration and Invoice Ninja.
            }
        }

        if ($this->invoiceNinja->configured()) {
            try {
                $s = $this->invoiceNinja->company()['settings'] ?? [];
                $in = [
                    'name' => $s['name'] ?? null,
                    'siren' => $s['id_number'] ?? null,
                    'vat_number' => $s['vat_number'] ?? null,
                    'address_line1' => $s['address1'] ?? null,
                    'address_line2' => $s['address2'] ?? null,
                    'post_code' => $s['postal_code'] ?? null,
                    'city' => $s['city'] ?? null,
                    'email' => $s['email'] ?? null,
                    'phone' => $s['phone'] ?? null,
                ];
            } catch (Throwable) {
                //
            }
        }

        $spdp = array_filter($spdp, fn ($v) => filled($v));

        // Sandbox: SUPER PDP only accepts invoices issued by its fictitious test company.
        $seller = ($spdp['environment'] ?? null) === 'sandbox'
            ? array_merge(array_filter($in, fn ($v) => filled($v)), $cfg, $spdp)
            : array_merge(array_filter($in, fn ($v) => filled($v)), $spdp, $cfg);

        $seller['account_siren'] = $spdp['siren'] ?? null;
        // Identity printed on the Invoice Ninja PDF, compared with BG-4 by OutboundService::coherence().
        $seller['pdf'] = ['name' => $in['name'] ?? null, 'address_line1' => $in['address_line1'] ?? null, 'post_code' => $in['post_code'] ?? null];
        $seller['country'] = strtoupper($seller['country'] ?? 'FR') === 'FRANCE' ? 'FR' : strtoupper($seller['country'] ?? 'FR');
        $seller['sources'] = ['config' => array_keys($cfg), 'superpdp' => array_keys(array_filter($spdp)), 'invoiceninja' => array_keys(array_filter($in))];

        return $seller;
    }

    private function ownAddress(): ?string
    {
        foreach ($this->superPdp->ownDirectoryEntries() as $entry) {
            if (! ($entry['is_replyto'] ?? false) && filled($entry['identifier'] ?? null) && in_array($entry['status'] ?? 'created', ['created', 'active', 'published', 'ok', 'pending'], true)) {
                return $entry['identifier'];
            }
        }

        foreach ($this->superPdp->ownDirectoryEntries() as $entry) {
            if (! ($entry['is_replyto'] ?? false) && filled($entry['identifier'] ?? null)) {
                return $entry['identifier'];
            }
        }

        return null;
    }
}
