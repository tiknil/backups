<?php

namespace Tiknil\Backups\Core;

use Symfony\Component\Process\Process;
use Tiknil\Backups\Core\Endpoints\EndpointFactory;
use Tiknil\Backups\Core\Endpoints\RcloneEndpoint;

/**
 * Thin wrapper around the rclone CLI, bound to a source and a destination
 * {@see RcloneEndpoint}.
 *
 * Callers only deal with paths relative to each disk root; remote declaration
 * and path syntax stay inside the endpoints.
 */
class Rclone
{
    private const BIN = 'rclone';

    public function __construct(
        private readonly RcloneEndpoint $source,
        private readonly RcloneEndpoint $destination,
        private readonly ?string $binary = null,
        private readonly ?int $transfers = null,
    ) {}

    /** Build an instance from two configured filesystem disk names. */
    public static function between(
        string $sourceDisk,
        string $destinationDisk,
        ?string $binary = null,
        ?int $transfers = null,
    ): self {
        return new self(
            self::endpoint($sourceDisk),
            self::endpoint($destinationDisk),
            $binary,
            $transfers,
        );
    }

    /**
     * Compare source and destination, streaming rclone's combined output.
     *
     * Status codes: '=' identical, '+' only in source, '*' different,
     * '-' only in destination, '!' error.
     *
     * Output is parsed while the process runs, so the raw stream is never held
     * in memory. Differences are written to a temporary report; identical
     * entries are dropped after being handed to `$onIdentical`.
     *
     * @param  callable(array{status: string, path: string}): void|null  $onIdentical  Called for each identical entry, as soon as it is read.
     * @param  array<int, string>  $excludes  rclone patterns to skip, relative to `$sourcePath`.
     * @return DiffReport Differences, readable after the check succeeds.
     */
    public function check(
        string $sourcePath = '',
        string $destinationPath = '',
        ?callable $onIdentical = null,
        array $excludes = [],
    ): DiffReport {
        $args = [
            'check',
            $this->source->path($sourcePath),
            $this->destination->path($destinationPath),
            '--combined', '-',
            ...$this->excludeArgs($excludes),
        ];

        $stream = new ProcessLines($this->process($args));

        $changes = new DiffReport;
        $hasErrors = false;

        try {
            // rclone check espone, file per file, una riga con un carattere iniziale che identifica lo stato di sync del file
            // = -> file identico
            // + -> file aggiunto
            // * -> file modificato
            // - -> file eliminato
            foreach ($stream->lines() as [$type, $line]) {
                if ($type !== Process::OUT) {
                    continue;
                }

                if (preg_match('/^([=+*!-])\s+(.+)$/', $line, $m) !== 1) {
                    continue;
                }

                $change = ['status' => $m[1], 'path' => $m[2]];

                if ($change['status'] === '=') {
                    if ($onIdentical !== null) {
                        $onIdentical($change);
                    }

                    continue;
                }

                $hasErrors = $hasErrors || $change['status'] === '!';

                $changes->append($change);
            }

            $exitCode = $stream->exitCode();

            if ($hasErrors || $exitCode > 1) {
                throw new \RuntimeException('rclone check failed: '.$stream->errorTail());
            }

            return $changes;
        } catch (\Throwable $e) {
            $changes->close();

            throw $e;
        }
    }

    /**
     * Copy new or changed files from source to destination.
     *
     * @param  callable(string): void|null  $onLine  Receives each progress line.
     * @param  array<int, string>  $excludes  rclone patterns to skip, relative to `$sourcePath`.
     */
    public function copy(
        string $sourcePath = '',
        string $destinationPath = '',
        ?callable $onLine = null,
        array $excludes = [],
    ): void {
        $args = [
            'copy',
            $this->source->path($sourcePath),
            $this->destination->path($destinationPath),
            ...$this->excludeArgs($excludes),
        ];

        $args[] = '--progress';
        $args[] = '--stats-one-line-date';
        $args[] = '--s3-upload-cutoff='.config('tk-backups.multipart_cutoff', '5M');
        $args[] = '--transfers='.$this->transfers();

        $stream = new ProcessLines($this->process($args));

        $exitCode = $stream->run(function (string $line) use ($onLine): void {
            if ($onLine !== null) {
                $onLine($line);
            }
        });

        if ($exitCode !== 0) {
            throw new \RuntimeException('rclone copy failed: '.$stream->errorTail());
        }
    }

    /** Check that rclone can resolve the disk and reach it with its credentials. */
    public static function probe(string $diskName, ?string $binary = null): void
    {
        $endpoint = self::endpoint($diskName);

        $process = new Process(
            [$binary ?? self::BIN, 'lsd', $endpoint->path(), '--retries', '1'],
            null,
            RcloneConfig::env() + $endpoint->env(),
        );

        $process->setTimeout(60);
        $process->mustRun();
    }

    /** Resolve a disk name to the matching rclone endpoint. */
    private static function endpoint(string $diskName): RcloneEndpoint
    {
        return EndpointFactory::make($diskName)
            ?? throw new \RuntimeException(
                "Disk '{$diskName}' must use the 's3' or 'local' driver for file backup."
            );
    }

    /** Parallel transfers: explicit value, else the package config. */
    private function transfers(): int
    {
        $transfers = $this->transfers ?? (int) config('tk-backups.transfer_concurrency', 16);

        return max(1, $transfers);
    }

    /**
     * @param  array<int, string>  $patterns
     * @return array<int, string>
     */
    private function excludeArgs(array $patterns): array
    {
        return array_map(fn (string $pattern): string => '--exclude='.$pattern, $patterns);
    }

    /** @param array<int, string> $args */
    private function process(array $args): Process
    {
        $env = RcloneConfig::env() + $this->source->env() + $this->destination->env();

        $process = new Process([$this->binary ?? self::BIN, ...$args], null, $env);
        $process->setTimeout(null);

        return $process;
    }
}
