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

// Books safety net — recomputes every ledger balance from its journal lines
// and exits non-zero on drift (see Finance\Console\ReconcileLedgersCommand).
// Read-only: it reports, it never silently "fixes" money; correcting needs
// a human running `finance:reconcile --fix`. Runs after the backup so a
// known-good dump exists first.
Schedule::command('finance:reconcile')
    ->dailyAt('00:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::critical('finance:reconcile found ledger drift — run it manually to inspect.'));

// Idempotency keys only need to outlive realistic client retries.
Schedule::call(fn () => \Illuminate\Support\Facades\DB::table('idempotency_keys')
    ->where('created_at', '<', now()->subDays(2))
    ->delete())
    ->name('prune-idempotency-keys')
    ->dailyAt('01:00')
    ->timezone(config('app.timezone'));

// "An untested backup is not a backup" — restores the latest
// backup:database dump into a disposable scratch database, compares
// every table's row count against live, and reconciles the restored
// ledgers too (see Console\Commands\VerifyBackupRestore). Weekly, not
// nightly — it's a correctness check on a backup that already runs
// nightly, not a substitute for it. Sunday, after the night's backup and
// reconcile have both had time to finish.
Schedule::command('backup:verify-restore')
    ->weeklyOn(0, '02:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::critical('backup:verify-restore failed — the latest backup may not be restorable. Investigate immediately.'));
