<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * "An untested backup is not a backup." Restores the most recent dump
 * BackupDatabase produced into a disposable scratch database, compares
 * every table's row count against the live database, and — since this is
 * a finance system — runs finance:reconcile against the restored copy
 * too, so a restore test also proves the books it recovers are actually
 * trustworthy, not just present. The scratch database is always dropped
 * afterward, success or failure, and this command NEVER touches the live
 * database itself.
 *
 * Intended to run on a schedule (weekly is enough — the backup itself
 * already runs nightly) separately from the backup job, so a corrupt or
 * incomplete dump is caught automatically rather than discovered for the
 * first time during a real incident.
 */
class VerifyBackupRestore extends Command
{
    protected $signature = 'backup:verify-restore';

    protected $description = 'Restore the latest backup into a scratch database and verify it matches the live one.';

    public function handle(): int
    {
        $directory = storage_path('app/private/backups');
        $latest = collect(File::files($directory))
            ->filter(fn ($file) => $file->getExtension() === 'sql')
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->first();

        if ($latest === null) {
            $this->error('No backup file found — run backup:database first.');

            return self::FAILURE;
        }

        $this->info("Verifying: {$latest->getFilename()}");

        $liveDatabase = DB::connection()->getDatabaseName();
        $scratchDatabase = 'restore_verify_'.now()->format('YmdHis');

        try {
            $this->createScratchDatabase($scratchDatabase);
            $this->restoreInto($scratchDatabase, $latest->getPathname());

            $mismatches = $this->compareRowCounts($liveDatabase, $scratchDatabase);
            $reconcileOk = $this->reconcileScratchDatabase($scratchDatabase);

            if ($mismatches === [] && $reconcileOk) {
                $this->info('Restore verified: every table matches and the ledgers reconcile.');

                return self::SUCCESS;
            }

            foreach ($mismatches as [$table, $liveCount, $restoredCount]) {
                $this->error("Row count mismatch on `{$table}`: live={$liveCount} restored={$restoredCount}");
            }
            if (! $reconcileOk) {
                $this->error('Restored database failed finance:reconcile.');
            }

            return self::FAILURE;
        } catch (\Throwable $e) {
            // A malformed/truncated dump throwing a raw SQL error IS the
            // finding this command exists to surface — report it the same
            // clean way as any other verification failure, not as an
            // unhandled crash, since this runs unattended on a schedule.
            $this->error("Restore verification failed: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $this->dropScratchDatabase($scratchDatabase);
        }
    }

    private function createScratchDatabase(string $database): void
    {
        DB::statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function dropScratchDatabase(string $database): void
    {
        DB::statement("DROP DATABASE IF EXISTS `{$database}`");
    }

    /**
     * Shells out to the `mysql` client rather than executing the dump's
     * statements through PDO one by one — the dump can contain thousands
     * of multi-row INSERTs, and piping the whole file through the client
     * is both simpler and exactly what a real disaster-recovery restore
     * would do (BackupDatabase's own docblock already notes this
     * environment may not have mysqldump, but the plain `mysql` client
     * for restoring TO a database it can already connect to is a much
     * safer assumption than that command's availability for dumping FROM
     * one).
     */
    private function restoreInto(string $database, string $dumpPath): void
    {
        $connection = config('database.connections.mysql');

        $process = new Process([
            'mysql',
            '-h', $connection['host'],
            '-P', (string) $connection['port'],
            '-u', $connection['username'],
            '--password='.$connection['password'],
            $database,
        ]);
        $process->setInput(File::get($dumpPath));
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('mysql restore failed: '.$process->getErrorOutput());
        }
    }

    /**
     * @return array<int, array{0: string, 1: int, 2: int}>
     */
    private function compareRowCounts(string $liveDatabase, string $scratchDatabase): array
    {
        $tables = collect(DB::select('SHOW TABLES'))->map(fn ($row) => array_values((array) $row)[0]);
        $mismatches = [];

        foreach ($tables as $table) {
            $liveCount = (int) DB::table("{$liveDatabase}.{$table}")->count();
            $restoredCount = (int) DB::table("{$scratchDatabase}.{$table}")->count();

            if ($liveCount !== $restoredCount) {
                $mismatches[] = [$table, $liveCount, $restoredCount];
            }
        }

        return $mismatches;
    }

    private function reconcileScratchDatabase(string $scratchDatabase): bool
    {
        config(['database.connections.restore_check' => array_merge(
            config('database.connections.mysql'),
            ['database' => $scratchDatabase],
        )]);

        /** @var \App\Modules\Finance\Services\FinanceService $finance */
        $finance = app(\App\Modules\Finance\Services\FinanceService::class);

        // Temporarily point the default connection at the scratch
        // database for this one call — FinanceService has no
        // connection-scoping parameter (it's never needed anywhere else),
        // so this is simpler and safer than adding one just for this
        // command's sake.
        $originalDefault = config('database.default');
        config(['database.default' => 'restore_check']);
        DB::purge('restore_check');

        try {
            return $finance->reconcileLedgers() === [];
        } finally {
            config(['database.default' => $originalDefault]);
            DB::purge('restore_check');
        }
    }
}
