<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Dumps every table to a single timestamped .sql file and keeps only the
 * most recent few — see KEEP. Written in plain PHP rather than shelling
 * out to mysqldump, since the shared hosting this project deploys to
 * (see zurie-backend-implementation-spec.md §18) can't be relied on to
 * have mysqldump available (or to allow exec() at all).
 *
 * Written to storage/app/private/backups — the `local` disk's root
 * (config/filesystems.php), which is not web-servable, unlike the
 * `public` disk Media uses. Scheduled to run daily at midnight — see
 * routes/console.php.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Dump the full database to a timestamped .sql file, keeping only the most recent backups.';

    private const KEEP = 3;

    private const CHUNK_SIZE = 500;

    public function handle(): int
    {
        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory);

        $filename = 'backup-'.now()->format('Y-m-d-His').'.sql';
        $path = $directory.'/'.$filename;

        try {
            $this->dump($path);
        } catch (\Throwable $e) {
            // Delete whatever partial file exists so it's never mistaken
            // for a real backup, and never touch the rotation below.
            File::delete($path);
            Log::error('Database backup failed', ['exception' => $e]);
            $this->error("Backup failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Backup written: {$filename}");
        $this->rotate($directory);

        return self::SUCCESS;
    }

    private function dump(string $path): void
    {
        $pdo = DB::connection()->getPdo();
        $database = DB::connection()->getDatabaseName();

        $tables = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => array_values((array) $row)[0]);

        $handle = fopen($path, 'w');

        fwrite($handle, "-- Backup of `{$database}` — ".now()->toDateTimeString()."\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $this->dumpTable($handle, $pdo, $table);
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    private function dumpTable($handle, \PDO $pdo, string $table): void
    {
        $createSql = DB::select("SHOW CREATE TABLE `{$table}`")[0]->{'Create Table'};

        fwrite($handle, "-- Table: {$table}\n");
        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($handle, $createSql.";\n\n");

        $total = DB::table($table)->count();
        $columns = null;
        $written = 0;

        for ($offset = 0; $offset < $total; $offset += self::CHUNK_SIZE) {
            $rows = DB::table($table)->offset($offset)->limit(self::CHUNK_SIZE)->get();

            if ($rows->isEmpty()) {
                break;
            }

            $columns ??= array_keys((array) $rows->first());
            $columnList = implode('`, `', $columns);

            $valueRows = $rows->map(function ($row) use ($columns, $pdo) {
                $values = array_map(function ($column) use ($row, $pdo) {
                    $value = $row->{$column};

                    return $value === null ? 'NULL' : $pdo->quote((string) $value);
                }, $columns);

                return '('.implode(', ', $values).')';
            })->implode(",\n");

            fwrite($handle, "INSERT INTO `{$table}` (`{$columnList}`) VALUES\n{$valueRows};\n");
            $written += $rows->count();
        }

        // Read back by VerifyBackupRestore, which checks a restore against
        // what the dump actually contains rather than against the live
        // database — live row counts move on (sessions, cache, new orders)
        // between the dump and the check.
        fwrite($handle, "-- Rows: {$table} {$written}\n\n");
    }

    private function rotate(string $directory): void
    {
        $backups = collect(File::files($directory))
            ->filter(fn ($file) => $file->getExtension() === 'sql')
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        $backups->slice(self::KEEP)->each(function ($file) {
            File::delete($file->getPathname());
            $this->info("Removed old backup: {$file->getFilename()}");
        });
    }
}
