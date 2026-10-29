<?php

namespace Tiknil\Backups\Core;

use Illuminate\Console\Command;
use Throwable;
use Tiknil\Backups\Core\Endpoints\EndpointFactory;
use Tiknil\Backups\Core\Endpoints\RcloneEndpoint;
use Tiknil\Backups\Core\Endpoints\S3Endpoint;

/**
 * Applica il legal hold ai file già sincronizzati ma rimasti sbloccati.
 *
 * Capita nelle sincronizzazioni iniziali o interrotte: i file vengono
 * caricati su S3, ma il processo termina prima di applicare il legal hold.
 *
 * Per ogni sorgente rclone confronta sorgente e destinazione e prende in
 * considerazione solo i file `identical`, cioè già presenti nel bucket. I
 * percorsi vengono scritti su un file temporaneo mentre rclone li emette
 * (su storage grandi non stanno in memoria), poi i legal hold mancanti
 * vengono applicati con un numero limitato di richieste S3 concorrenti.
 *
 * Ogni file costa una HEAD; la scrittura del legal hold avviene solo se manca.
 * Tag e retention non vengono toccati.
 */
class FilesLegalHold
{
    /**
     * @param  array<int, array{disk?: string, folder?: string|array<int, string>, exclude?: string|array<int, string>}>  $sources
     */
    public function __construct(
        private readonly array $sources,
        private readonly S3Disk $destination,
        private readonly string $prefix,
        private readonly int $concurrency = 8,
        private readonly ?string $binary = null,
    ) {}

    /** @return bool False when at least one source could not be processed. */
    public function execute(Command $command): bool
    {
        $destinationEndpoint = new S3Endpoint($this->destination);
        $lock = new ObjectLock($this->destination, $this->concurrency);
        $success = true;

        foreach ($this->sources as $config) {
            $command->newLine();

            $sources = FileSource::list((array) $config);

            if ($sources === []) {
                $command->warn('Skipping a file source without a disk name.');

                continue;
            }

            foreach ($sources as $source) {
                try {
                    $this->hold($source, $destinationEndpoint, $lock, $command);
                } catch (Throwable $e) {
                    $command->error("{$source->scope()}: {$e->getMessage()}");
                    $success = false;
                }
            }
        }

        return $success;
    }

    private function hold(
        FileSource $source,
        RcloneEndpoint $destinationEndpoint,
        ObjectLock $lock,
        Command $command,
    ): void {
        $sourceEndpoint = EndpointFactory::make($source->disk);

        if ($sourceEndpoint === null) {
            $command->warn("Skipping disk '{$source->disk}': unsupported driver.");

            return;
        }

        $destinationPath = $source->destinationPath($this->prefix);

        if ($this->destination->isEmpty($destinationPath)) {
            $command->line("Skipping {$source->scope()}: nothing uploaded yet.");

            return;
        }

        $command->info("Checking {$source->scope()} for identical files");

        $identical = new PathList;
        $applied = 0;
        $processed = 0;

        try {
            $rclone = new Rclone($sourceEndpoint, $destinationEndpoint, $this->binary);

            $diff = $rclone->check(
                $source->folder,
                $destinationPath,
                onIdentical: function (array $change) use ($identical): void {
                    $identical->append($change['path']);
                },
                excludes: $source->excludes,
            );
            $diff->close();

            $command->line("  {$identical->count()} identical files found, checking the legal hold");

            $lock->ensureHold(
                $this->objectKeys($identical, $destinationPath),
                onDone: function (string $key, bool $locked) use (&$applied, &$processed, $command): void {
                    $processed++;

                    if ($locked) {
                        $applied++;
                    }

                    if ($processed % 1000 === 0) {
                        $command->line("  {$processed} files checked, {$applied} legal holds applied");
                    }
                },
            );

            $command->info("✓ {$identical->count()} identical files, {$applied} legal holds applied.");
        } finally {
            $identical->close();
        }
    }

    /**
     * Map relative paths to absolute object keys.
     *
     * @return \Generator<int, string>
     */
    private function objectKeys(iterable $paths, string $destinationPath): \Generator
    {
        foreach ($paths as $path) {
            yield $this->destination->fullPath($destinationPath, $path);
        }
    }
}
