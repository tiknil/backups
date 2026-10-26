<?php

namespace Tiknil\Backups\Commands;

use Illuminate\Console\Command;
use Tiknil\Backups\Core\Endpoints\EndpointFactory;
use Tiknil\Backups\Core\Rclone;

class BackupRestore extends Command
{
    protected $signature = 'backup:restore'
        .' {disk : Filesystem disk to restore}'
        .' {folder? : Optional sub-folder within the disk}'
        .' {--backup-disk= : Backup disk (defaults to tk-backups.destination_disk)}'
        .' {--prefix=files : Prefix used during backup}'
        .' {--dry-run : Show what would be restored without copying}'
        .' {--force : Restore without confirmation}';

    protected $description = 'Restore storage files from the backup bucket via rclone (inverse of the files backup)';

    public function handle(): int
    {
        $disk = $this->argument('disk');
        $folder = trim((string) ($this->argument('folder') ?? ''), '/');
        $backupDisk = (string) ($this->option('backup-disk') ?: config('tk-backups.destination_disk'));
        $prefix = trim((string) $this->option('prefix'), '/');

        try {
            $target = EndpointFactory::make($disk);
            $backup = EndpointFactory::make($backupDisk);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($target === null) {
            $this->error("Disk '{$disk}' uses an unsupported driver (only 's3' and 'local' can be restored).");

            return self::FAILURE;
        }

        if ($backup === null) {
            $this->error("Backup disk '{$backupDisk}' uses an unsupported driver.");

            return self::FAILURE;
        }

        $backupPath = trim(implode('/', array_filter([$prefix, $disk, $folder])), '/');
        $scope = trim($disk.($folder !== '' ? '/'.$folder : ''), '/');

        $rclone = new Rclone($backup, $target);

        $this->line("Checking backup for {$scope}");

        $same = 0;
        $diff = $rclone->check(
            $backupPath,
            $folder,
            onIdentical: function () use (&$same): void {
                $same++;
            },
        );

        try {
            $restore = $diff->countChanged();
            $extra = $diff->countDeleted();

            if ($restore === 0 && $same === 0) {
                $this->error("No backup found at {$backupDisk}:{$backupPath}.");

                return self::FAILURE;
            }

            $this->line('To restore: '.$restore.', unchanged: '.$same.', only on disk: '.$extra.'.');

            foreach ($diff->changed() as $index => $path) {
                if ($index >= 50) {
                    break;
                }

                $this->line("  + {$path}");
            }

            if ($restore > 50) {
                $this->line('  … and '.($restore - 50).' more.');
            }

            if ($restore === 0) {
                $this->info('Nothing to restore.');

                return self::SUCCESS;
            }

            if ($this->option('dry-run')) {
                $this->info('Dry run: nothing copied.');

                return self::SUCCESS;
            }

            if (!$this->option('force') && !$this->confirm('Restore '.$restore." files to disk '{$scope}'?")) {
                $this->line('Aborted.');

                return self::SUCCESS;
            }

            $rclone->copy(
                $backupPath,
                $folder,
                fn (string $line) => $this->line('[rclone] '.$line),
            );

            $this->info('✓ Restored '.$restore.' files to '.$scope.'.');

            return self::SUCCESS;
        } finally {
            $diff->close();
        }
    }
}
