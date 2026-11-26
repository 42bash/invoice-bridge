<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;

/** Consistent copy of the live SQLite file, picked up by the host backup (rsync of a live WAL database is not). */
Artisan::command('einvoicing:backup-db {--keep=7}', function () {
    $dir = storage_path('database/backup');
    File::ensureDirectoryExists($dir);
    $target = $dir.'/einvoicing-'.now()->format('Ymd-His').'.sqlite';

    DB::statement('VACUUM INTO ?', [$target]);

    collect(File::glob($dir.'/einvoicing-*.sqlite'))->sort()->reverse()->slice((int) $this->option('keep'))->each(fn ($f) => File::delete($f));
    $this->info("Copie : {$target}");
})->purpose('Copie cohérente de la base SQLite du connecteur');

Schedule::command('einvoicing:sync')
    ->cron('*/'.max(1, (int) config('einvoicing.schedule.sync_every_minutes', 10)).' * * * *')
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('einvoicing:sync --full')->dailyAt('03:30')->withoutOverlapping(60);

Schedule::command('einvoicing:backup-db')->dailyAt('01:45');

Schedule::command('model:prune')->daily();
