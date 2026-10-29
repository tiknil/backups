<?php

namespace Tiknil\Backups\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Tiknil\Backups\Core\Endpoints\EndpointFactory;
use Tiknil\Backups\Core\Rclone;
use Tiknil\Backups\Core\S3Disk;

/**
 * Preflight checks to verify that the environment is ready to run backups.
 *
 * Verifies the destination S3 disk (configuration + working credentials),
 * the presence of mysqldump, the optional rclone binary and the archive
 * password. Exits with a non-zero code when a required check fails.
 */
class BackupChecks extends Command
{
    protected $signature = 'backup:checks';

    protected $description = 'Verify that everything is ready to run the backups';

    private bool $failed = false;

    private bool $rcloneAvailable = false;

    public function handle(): int
    {
        $this->checkDisk();
        $this->checkMysqldump();
        $this->checkRclone();
        $this->checkFileSources();
        $this->checkArchivePassword();

        $this->newLine();

        if ($this->failed) {
            $this->error('Backup checks failed.');

            return self::FAILURE;
        }

        $this->info('All checks passed. Ready to backup.');

        return self::SUCCESS;
    }

    private function checkDisk(): void
    {
        $this->line('Destination disk');

        $diskName = config('tk-backups.destination_disk');

        if (empty($diskName)) {
            $this->failMessage('No destination disk configured (tk-backups.destination_disk).');

            return;
        }

        $diskConfig = config("filesystems.disks.{$diskName}");

        if (!is_array($diskConfig)) {
            $this->failMessage("Disk '{$diskName}' is not defined in filesystems.disks.");

            return;
        }

        if (($diskConfig['driver'] ?? null) !== 's3') {
            $this->failMessage("Disk '{$diskName}' must use the 's3' driver.");

            return;
        }

        $this->pass("Disk '{$diskName}' is defined and uses the s3 driver.");

        try {
            S3Disk::fromDiskName($diskName)->ping();
        } catch (\Throwable $e) {
            $this->failMessage("Could not reach disk '{$diskName}': {$e->getMessage()}");

            return;
        }

        $this->pass("Credentials for disk '{$diskName}' are valid and the bucket is reachable.");
    }

    private function checkMysqldump(): void
    {
        $this->line('mysqldump');

        if ($this->binaryAvailable(['mysqldump', '--version'])) {
            $this->pass('mysqldump is available.');

            return;
        }

        $this->failMessage('mysqldump is not available in PATH.');
    }

    private function checkRclone(): void
    {
        $this->line('rclone');

        if ($this->binaryAvailable(['rclone', 'version'])) {
            $this->rcloneAvailable = true;
            $this->pass('rclone is available.');

            return;
        }

        if ((array) config('tk-backups.file_sources') !== []) {
            $this->failMessage('rclone is not available in PATH (required by tk-backups.file_sources).');

            return;
        }

        $this->skip('rclone is not available (optional: no file sources configured).');
    }

    private function checkFileSources(): void
    {
        $sources = (array) config('tk-backups.file_sources', []);

        if ($sources === []) {
            return;
        }

        $this->line('File sources');

        foreach ($sources as $source) {
            $diskName = is_array($source) ? ($source['disk'] ?? null) : null;

            if (!is_string($diskName) || $diskName === '') {
                $this->failMessage('Each tk-backups.file_sources entry must define a disk name.');

                continue;
            }

            $invalid = $this->invalidOption((array) $source);

            if ($invalid !== null) {
                $this->failMessage("File source '{$diskName}' is invalid: {$invalid}");

                continue;
            }

            try {
                if (EndpointFactory::make($diskName) === null) {
                    $this->failMessage("Disk '{$diskName}' uses an unsupported driver for file backup.");

                    continue;
                }

                if ($this->rcloneAvailable) {
                    Rclone::probe($diskName);
                }

                $this->pass("File source '{$diskName}' is configured.");
            } catch (\Throwable $e) {
                $this->failMessage("File source '{$diskName}' is invalid: {$e->getMessage()}");
            }
        }
    }

    /**
     * `folder` and `exclude` accept a single string or a list of strings.
     *
     * @param  array<string, mixed>  $source
     */
    private function invalidOption(array $source): ?string
    {
        foreach (['folder', 'exclude'] as $key) {
            $value = $source[$key] ?? null;

            if ($value === null || is_string($value)) {
                continue;
            }

            if (is_array($value) && array_filter($value, fn (mixed $item): bool => !is_string($item)) === []) {
                continue;
            }

            return "'{$key}' must be a string or a list of strings.";
        }

        return null;
    }

    private function checkArchivePassword(): void
    {
        $this->line('Archive password');

        if (filled(config('tk-backups.laravel-backups.backup.password'))) {
            $this->pass('Archive password is set.');

            return;
        }

        $this->failMessage('Archive password is not set (BACKUP_ARCHIVE_PASSWORD).');
    }

    /** @param array<int, string> $command */
    private function binaryAvailable(array $command): bool
    {
        try {
            $process = new Process($command);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function pass(string $message): void
    {
        $this->line("  <fg=green>✓</> {$message}");
    }

    private function failMessage(string $message): void
    {
        $this->failed = true;
        $this->line("  <fg=red>✗</> {$message}");
    }

    private function skip(string $message): void
    {
        $this->line("  <fg=yellow>○</> {$message}");
    }
}
