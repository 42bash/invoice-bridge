<?php

namespace App\Providers;

use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\InvoiceNinja\Statics;
use App\Services\SuperPdp\SuperPdpClient;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvoiceNinjaClient::class, fn () => InvoiceNinjaClient::fromConfig());
        $this->app->singleton(SuperPdpClient::class, fn () => SuperPdpClient::fromConfig());
        $this->app->singleton(Statics::class);
    }

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
