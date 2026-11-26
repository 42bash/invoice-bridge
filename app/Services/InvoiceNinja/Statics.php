<?php

namespace App\Services\InvoiceNinja;

use Illuminate\Support\Facades\Cache;

/** Invoice Ninja reference data (countries, currencies), cached for a day. */
class Statics
{
    private const FALLBACK_COUNTRIES = ['250' => 'FR', '56' => 'BE', '276' => 'DE', '724' => 'ES', '380' => 'IT', '442' => 'LU', '528' => 'NL', '756' => 'CH', '826' => 'GB', '840' => 'US', '372' => 'IE'];

    private ?array $data = null;

    public function __construct(private readonly InvoiceNinjaClient $client) {}

    public function country(string|int|null $id): ?string
    {
        if (blank($id)) {
            return null;
        }

        $countries = $this->load()['countries'];

        return $countries[(string) $id] ?? self::FALLBACK_COUNTRIES[(string) $id] ?? null;
    }

    public function countryId(?string $alpha2): ?string
    {
        if (blank($alpha2)) {
            return null;
        }

        $id = array_search(strtoupper($alpha2), $this->load()['countries'], true);

        return $id === false ? null : (string) $id;
    }

    public function currencyId(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        $id = array_search(strtoupper($code), $this->load()['currencies'], true);

        return $id === false ? null : (string) $id;
    }

    public function currency(string|int|null $id): ?string
    {
        if (blank($id)) {
            return null;
        }

        return $this->load()['currencies'][(string) $id] ?? null;
    }

    private function load(): array
    {
        return $this->data ??= Cache::remember('invoiceninja.statics', now()->addDay(), function () {
            try {
                $statics = $this->client->get('statics');
            } catch (\Throwable) {
                return ['countries' => self::FALLBACK_COUNTRIES, 'currencies' => ['1' => 'USD', '3' => 'EUR']];
            }

            return [
                'countries' => collect($statics['countries'] ?? [])->mapWithKeys(fn ($c) => [(string) $c['id'] => $c['iso_3166_2'] ?? null])->filter()->all(),
                'currencies' => collect($statics['currencies'] ?? [])->mapWithKeys(fn ($c) => [(string) $c['id'] => $c['code'] ?? null])->filter()->all(),
            ];
        });
    }
}
