<?php

namespace Tiknil\Backups\Core;

use Illuminate\Console\Command;
use Tiknil\Backups\Core\Endpoints\EndpointFactory;

class FilesIncrementalBackup
{
    private const DELETED_TAG = 'backup-deleted-at';

    private const DELETED_RETENTION_DAYS = 30;

    /**
     * @param  array<int, array{disk: string, folder?: string}>  $sources
     */
    public function __construct(
        private readonly array $sources,
        private readonly string $destinationDisk,
        private readonly string $prefix,
    ) {}

    public function execute(Command $command): int
    {
        $destination = S3Disk::fromDiskName($this->destinationDisk);
        $destinationEndpoint = EndpointFactory::make($this->destinationDisk)
            ?? throw new \RuntimeException(
                "Destination disk '{$this->destinationDisk}' must use the 's3' or 'local' driver."
            );
        $totalChanged = 0;
        $totalDeleted = 0;

        foreach ($this->sources as $source) {
            $command->newLine();
            $diskName = $source['disk'];
            $sourceEndpoint = EndpointFactory::make($diskName);

            if ($sourceEndpoint === null) {
                $command->warn("Skipping disk '{$diskName}': unsupported driver.");

                continue;
            }

            $rclone = new Rclone($sourceEndpoint, $destinationEndpoint);
            $folder = trim($source['folder'] ?? '', '/');
            $destinationPath = trim(implode('/', array_filter([
                $this->prefix,
                $diskName,
                $folder,
            ], fn (string $part): bool => $part !== '')), '/');
            $scope = trim($diskName.($folder !== '' ? '/'.$folder : ''), '/');

            $command->info("Checking {$scope} for diffs");
            $diff = $rclone->check($folder, $destinationPath);
            $changed = array_values(array_filter(
                $diff,
                fn (array $change): bool => in_array($change['status'], ['+', '*'], true),
            ));
            $unchanged = array_values(array_filter(
                $diff,
                fn (array $change): bool => $change['status'] === '=',
            ));
            $deleted = array_values(array_filter(
                $diff,
                fn (array $change): bool => $change['status'] === '-',
            ));

            if ($changed !== []) {
                $rclone->copy(
                    $folder,
                    $destinationPath,
                    fn (string $line) => $command->line('[rclone] '.$line),
                );

                foreach ($changed as $change) {
                    $key = $destination->fullPath($destinationPath, $change['path']);
                    $destination->legalHold($key, true);
                    $destination->setTag($key, self::DELETED_TAG, null);
                    $totalChanged++;
                }
            }

            foreach ($unchanged as $change) {
                $key = $destination->fullPath($destinationPath, $change['path']);

                if ($destination->tag($key, self::DELETED_TAG) !== null) {
                    $destination->legalHold($key, true);
                    $destination->setTag($key, self::DELETED_TAG, null);
                }
            }

            foreach ($deleted as $change) {
                $key = $destination->fullPath($destinationPath, $change['path']);
                $archivePath = trim($destinationPath.'/'.$change['path'], '/');

                $command->line("  ⊘ {$archivePath}");
                $this->expireDeletedFile($destination, $key);
                $totalDeleted++;
            }
        }

        $command->newLine();

        if ($totalChanged > 0) {
            $command->info("Transferred {$totalChanged} new or changed files.");
        }

        if ($totalDeleted > 0) {
            $command->info("Processed {$totalDeleted} deleted files (30-day COMPLIANCE retention applied).");
        }

        if ($totalChanged === 0 && $totalDeleted === 0) {
            $command->info('No file changes detected.');
        }

        return $totalChanged;
    }

    private function expireDeletedFile(S3Disk $disk, string $key): void
    {
        if ($disk->tag($key, self::DELETED_TAG) === null) {
            // Set and verify retention before releasing the hold. If either
            // operation fails, the exception leaves the legal hold untouched.
            $disk->retainFor($key, self::DELETED_RETENTION_DAYS);
            $disk->setTag($key, self::DELETED_TAG, now('UTC')->toIso8601String());
        }

        // If releasing the hold fails, the marker remains and the next run
        // retries only this step without extending the retention period.
        $disk->legalHold($key, false);
    }
}
