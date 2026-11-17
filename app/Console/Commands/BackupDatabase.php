<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * mysqldump -> .sql.gz, keep the newest N, optionally copy off-server.
 * Uses proc_open (Process), which Hostinger allows; exec/shell_exec are off.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Dump the database to a gzip file, keep the last N, copy off-server if configured';

    public function handle(): int
    {
        $dir = config('backup.path');
        File::ensureDirectoryExists($dir);

        $stamp = now()->format('Ymd-His');
        $sql = "{$dir}/iqs-{$stamp}.sql";
        $gz = "{$sql}.gz";
        $db = config('database.connections.'.(config('backup.connection') ?: config('database.default')));

        try {
            if (($db['driver'] ?? null) === 'sqlite') {
                File::copy($db['database'], $sql);
            } else {
                // Password through the environment, never on the command line.
                $result = Process::env(['MYSQL_PWD' => (string) ($db['password'] ?? '')])
                    ->timeout(600)
                    ->run([
                        config('backup.mysqldump'),
                        '--single-transaction', '--quick', '--routines', '--no-tablespaces',
                        '-h', (string) $db['host'], '-P', (string) $db['port'], '-u', (string) $db['username'],
                        '--result-file='.$sql, (string) $db['database'],
                    ]);
                if (! $result->successful()) {
                    throw new RuntimeException(trim($result->errorOutput()) ?: 'mysqldump failed');
                }
            }

            $this->gzip($sql, $gz);
        } catch (\Throwable $e) {
            File::delete([$sql, $gz]);
            Cache::forever('backup:last_error', ['at' => now()->toIso8601String(), 'message' => $e->getMessage()]);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        File::delete($sql);

        if ($disk = config('backup.copy_to_disk')) {
            $stream = fopen($gz, 'rb');
            Storage::disk($disk)->writeStream('iqs-backups/'.basename($gz), $stream);
            is_resource($stream) && fclose($stream);
        }

        $this->prune($dir);
        Cache::forget('backup:last_error');
        $this->info('Backup written: '.basename($gz).' ('.number_format(filesize($gz) / 1024, 1).' KB)');
        $this->toDrive($gz);

        return self::SUCCESS;
    }

    /**
     * A copy in the business Google Drive (Settings > Google Drive), folder "DB Backup": the newest
     * config('backup.drive_keep') stay there. A failed copy never fails the backup; System health shows it.
     */
    private function toDrive(string $gz): void
    {
        $drive = \App\Models\GoogleDrive::current();
        if (! $drive->isConnected() || ! $drive->hasKeys()) {
            Cache::forget('backup:drive');

            return;
        }
        try {
            $service = app(\App\Services\Drive\GoogleDriveService::class);
            $service->upload($drive, 'backups', basename($gz), 'application/gzip', (string) file_get_contents($gz));
            $service->keepNewest($drive, 'backups', (int) config('backup.drive_keep', 30));
            Cache::forever('backup:drive', ['at' => now()->toIso8601String(), 'ok' => true]);
            $this->info('Copied to Google Drive (DB Backup).');
        } catch (\Throwable $e) {
            Cache::forever('backup:drive', ['at' => now()->toIso8601String(), 'ok' => false, 'message' => $e->getMessage()]);
            \Illuminate\Support\Facades\Log::warning('Backup copy to Google Drive failed: '.$e->getMessage());
            $this->warn('Google Drive copy failed: '.$e->getMessage());
        }
    }

    /** Stream in 1 MB chunks so a large dump never sits in memory. */
    private function gzip(string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = gzopen($to, 'wb6');
        while (! feof($in)) {
            gzwrite($out, fread($in, 1048576));
        }
        fclose($in);
        gzclose($out);
    }

    private function prune(string $dir): void
    {
        $files = collect(File::glob($dir.'/iqs-*.sql.gz'))->sortDesc()->values();
        File::delete($files->slice((int) config('backup.keep', 14))->all());
    }
}
