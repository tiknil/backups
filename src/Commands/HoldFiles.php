<?php

namespace Tiknil\Backups\Commands;

use Illuminate\Console\Command;
use Throwable;
use Tiknil\Backups\Core\FilesLegalHold;
use Tiknil\Backups\Core\S3Disk;

/**
 * Riapplica il legal hold ai file già caricati sul bucket di backup ma
 * rimasti sbloccati (tipico delle sincronizzazioni iniziali interrotte).
 *
 * Legge le stesse sorgenti del backup incrementale (tk-backups.file_sources)
 * e considera solo i file `identical`, cioè già presenti nel bucket.
 */
class HoldFiles extends Command
{
    protected $signature = 'backup:hold-files';

    protected $description = 'Apply the S3 legal hold to the backed up files that are missing it';

    public function handle(): int
    {
        $sources = (array) config('tk-backups.file_sources', []);

        if ($sources === []) {
            $this->warn('No file sources configured (tk-backups.file_sources).');

            return self::SUCCESS;
        }

        try {
            $destination = S3Disk::fromDiskName(config('tk-backups.destination_disk'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $holds = new FilesLegalHold(
            $sources,
            $destination,
            'files',
            (int) config('tk-backups.concurrency', 8),
        );

        if (!$holds->execute($this)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Legal holds verified.');

        return self::SUCCESS;
    }
}
