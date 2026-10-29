<?php

namespace Tiknil\Backups\Core;

use Illuminate\Console\Command;
use Tiknil\Backups\Core\Endpoints\EndpointFactory;

class FilesIncrementalBackup
{
    /**
     * @param  array<int, array{disk: string, folder?: string|array<int, string>, exclude?: string|array<int, string>}>  $sources
     */
    public function __construct(
        private readonly array $sources,
        private readonly string $destinationDisk,
        private readonly string $prefix,
        private readonly int $concurrency = 8,
    ) {}

    public function execute(Command $command): int
    {
        $destination = S3Disk::fromDiskName($this->destinationDisk);
        $lock = new ObjectLock($destination, $this->concurrency);
        $destinationEndpoint = EndpointFactory::make($this->destinationDisk)
            ?? throw new \RuntimeException(
                "Destination disk '{$this->destinationDisk}' must use the 's3' or 'local' driver."
            );
        $totalChanged = 0;
        $totalDeleted = 0;

        foreach ($this->sources as $config) {
            $command->newLine();

            // Ogni source nel config diventa potenzialmente un'array di effettive sources, se sono indicate più cartelle da includere
            $sources = FileSource::list((array) $config);

            if ($sources === []) {
                $command->warn('Skipping a file source without a disk name.');

                continue;
            }

            $diskName = $sources[0]->disk;
            $sourceEndpoint = EndpointFactory::make($diskName);

            if ($sourceEndpoint === null) {
                $command->warn("Skipping disk '{$diskName}': unsupported driver.");

                continue;
            }

            $rclone = new Rclone($sourceEndpoint, $destinationEndpoint);

            foreach ($sources as $source) {
                ['changed' => $changed, 'deleted' => $deleted] = $this->backupSource($source, $rclone, $destination, $lock, $command);
                $totalChanged += $changed;
                $totalDeleted += $deleted;
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

    /** @return array{changed: int, deleted: int} Counts of processed files. */
    private function backupSource(
        FileSource $source,
        Rclone $rclone,
        S3Disk $destination,
        ObjectLock $lock,
        Command $command,
    ): array {
        $folder = $source->folder;
        $destinationPath = $source->destinationPath($this->prefix);

        $command->info("Checking {$source->scope()} for diffs");

        // Identical files are handled as they stream in: with millions of
        // them, keeping the list around would exhaust memory for nothing.
        $diff = $rclone->check(
            $folder,
            $destinationPath,
            onIdentical: function (array $change): void {
                // POTENTIAL ISSUE:
                // A deleted (but still in the retention period) file could be restored.
                // In that case, the legalHold tag should be applied.
                // However, checking the presente on the DELETED tag each time is very time consuming in large buckets,
                // because each file would trigger a request to s3 checking for the tag.
                //
                // We decide to ignore this use case for performance reason and ignore the identical files.
                // Note that, in order for the restored file to be identical, the updated_at timestamp and the size should be equal as well.
                // Even in that case, after the retention period the file would be deleted and, the day after, reupload as a new file and marked with the legal hold

                /*
                $key = $destination->fullPath($destinationPath, $change['path']);
                if ($destination->tag($key, ObjectLock::DELETED_TAG) !== null) {
                    $destination->legalHold($key, true);
                    $destination->setTag($key, ObjectLock::DELETED_TAG, null);
                }
                */
            },
            excludes: $source->excludes,
        );

        $transferred = 0;
        $deletedCount = 0;

        try {
            if ($diff->countChanged() > 0) {
                $rclone->copy(
                    $folder,
                    $destinationPath,
                    fn (string $line) => $command->line('[rclone] '.$line),
                    $source->excludes,
                );

                // Legal holds and tags are applied a few objects at a time.
                $lock->protect(
                    $this->objectKeys($diff->changed(), $destination, $destinationPath),
                    onDone: function () use (&$transferred): void {
                        $transferred++;
                    },
                );
            }

            if ($diff->countDeleted() > 0) {
                $lock->expire(
                    $this->objectKeys($diff->deleted(), $destination, $destinationPath),
                    onDone: function (string $key) use ($command, &$deletedCount): void {
                        $command->line("  ⊘ {$key}");
                        $deletedCount++;
                    },
                );
            }
        } finally {
            $diff->close();
        }

        return ['changed' => $transferred, 'deleted' => $deletedCount];
    }

    /**
     * Map relative paths to absolute object keys.
     *
     * @param  iterable<string>  $paths
     * @return \Generator<int, string>
     */
    private function objectKeys(iterable $paths, S3Disk $disk, string $destinationPath): \Generator
    {
        foreach ($paths as $path) {
            yield $disk->fullPath($destinationPath, $path);
        }
    }
}
