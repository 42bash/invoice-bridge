<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\DashboardAuth;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/invoiceninja', [WebhookController::class, 'invoiceNinja'])
    ->middleware('throttle:60,1')
    ->name('webhooks.invoiceninja');

Route::middleware(DashboardAuth::class)->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/journal', [DashboardController::class, 'journal'])->name('journal');
    Route::post('/sync', [DashboardController::class, 'sync'])->name('sync');

    Route::get('/emises/{document}', [DashboardController::class, 'outbound'])->name('outbound.show');
    Route::post('/emises/{document}/preparer', [DashboardController::class, 'prepare'])->name('outbound.prepare');
    Route::post('/emises/{document}/transmettre', [DashboardController::class, 'send'])->name('outbound.send');
    Route::post('/emises/{document}/encaissement', [DashboardController::class, 'reportPayment'])->name('outbound.payment');

    Route::get('/recues/{document}', [DashboardController::class, 'inbound'])->name('inbound.show');
    Route::post('/recues/{document}/importer', [DashboardController::class, 'import'])->name('inbound.import');
    Route::post('/recues/{document}/ecarter', [DashboardController::class, 'ignore'])->name('inbound.ignore');
    Route::post('/recues/{document}/statut', [DashboardController::class, 'buyerStatus'])->name('inbound.status');

    Route::get('/fichiers/{direction}/{id}/{kind}', [DashboardController::class, 'file'])
        ->whereIn('direction', ['outbound', 'inbound'])
        ->whereNumber('id')
        ->name('file');
});
