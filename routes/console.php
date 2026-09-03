<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Full DB dump every midnight via BackupDatabase (app/Console/Commands) —
// keeps the 3 most recent .sql files under storage/app/private/backups.
// ->timezone() is explicit rather than relying on the server's own system
// clock, so "midnight" always means midnight in APP_TIMEZONE
// (Africa/Dar_es_Salaam) regardless of what timezone the host box is set
// to. ->withoutOverlapping() skips a run if the previous dump is still in
// progress instead of starting a second one concurrently.
//
// Requires one cron entry on the server (shared hosting deploy, see
// zurie-backend-implementation-spec.md §19):
//   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('backup:database')
    ->dailyAt('00:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
