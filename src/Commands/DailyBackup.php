<?php

namespace Tiknil\Backups\Commands;

use Illuminate\Console\Command;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Tiknil\Backups\Core\Dumpers\MySqlDumper;
use Tiknil\Backups\Core\FilesIncrementalBackup;
use Tiknil\Backups\Core\RetentionPeriod;
use Tiknil\Backups\Core\S3Disk;

/**
 * Single daily backup command that uploads to S3 with Object Lock
 * and auto-promotes the same file to weekly/monthly/yearly prefixes.
 *
 * Promotion Logic:
 * - Daily: Created only if no backup exists for the current day.
 * - Weekly/Monthly/Yearly: Promoted only if no backup exists for the current period.
 *
 * Scheduler: one entry only
 *   Schedule::command('backup')->dailyAt('02:00')->withoutOverlapping();
 *
 * Retention is enforced by S3 Object Lock (COMPLIANCE mode).
 * Cleanup is handled by S3 Lifecycle rules, not by backup:clean.
 */
class DailyBackup extends Command
{
    protected $signature = 'backup  {--timeout=3600 : Maximum seconds for the spatie backup process}';

    protected $description = 'Daily backup on S3 with Object Lock; auto-promotion to weekly/monthly/yearly and cloud files diff-only backup';

    public function handle(): int
    {
        $now = now('UTC');

        $s3Disk = S3Disk::fromDiskName(config('tk-backups.destination_disk'));

        // ─────────────────────────────────────────────────────────────────
        //  Step 1. Daily backup using spatie/laravel-backup
        // ─────────────────────────────────────────────────────────────────

        $fileName = $now->format('Y-m-d-H-i-s').'.zip';
        $dailyPath = 'daily/'.$now->format('Y-m-d');
        $dailyKey = $s3Disk->fullPath($dailyPath, $fileName);

        $this->line("Creating daily backup → {$dailyPath}/{$fileName}");
        $backupExitCode = $this->performLaravelBackup($dailyPath, $fileName);

        if ($backupExitCode !== self::SUCCESS) {
            $this->error('backup:run failed with errors');

            return $backupExitCode;
        }

        $s3Disk->applyRetention($dailyKey, RetentionPeriod::Daily);

        $this->info('✓ Daily created and locked.');

        // ─────────────────────────────────────────────────────────────────
        //  Step 2. Promotion to weekly / monthly / yearly
        // ─────────────────────────────────────────────────────────────────

        foreach (RetentionPeriod::promotable() as $period) {
            $basePath = $period->basePath($now);

            if (!$s3Disk->isEmpty($basePath)) {
                $this->line("Skipping {$period->name}: {$basePath} already backed up.");

                continue;
            }

            $this->line("Promoting backup → {$basePath}");

            $targetKey = $s3Disk->fullPath($basePath, $fileName);
            $s3Disk->copy($dailyKey, $targetKey);
            $s3Disk->applyRetention($targetKey, $period);

            $this->info("✓ {$period->name} promoted and locked.");
        }

        // ─────────────────────────────────────────────────────────────────
        //  Step 3. Incremental backup of remote files
        // ─────────────────────────────────────────────────────────────────

        $fileBackup = new FilesIncrementalBackup(
            (array) config('tk-backups.file_sources', []),
            config('tk-backups.destination_disk'),
            'files',
        );
        $fileBackup->execute($this);

        $this->newLine();
        $this->info('✓ File backup completed successfully.');

        return self::SUCCESS;

    }

    private function performLaravelBackup(string $path, string $filename): int
    {
        DbDumperFactory::extend('mysql', fn () => new MySqlDumper);
        $this->newLine();
        $this->line('Starting spatie backup:run process');
        $exitCode = $this->call('backup:run', [
            '--destination-path' => $path,
            '--filename' => $filename,
            '--timeout' => (int) $this->option('timeout'),
            '--disable-notifications' => true,
            '--config' => 'tk-backups.laravel-backups', // Use the config defined by this package
        ]);

        $this->line('spatie backup:run finished');
        $this->newLine();

        return $exitCode;
    }
}
