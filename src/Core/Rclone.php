<?php

namespace Tiknil\Backups\Core;

use Symfony\Component\Process\Process;
use Tiknil\Backups\Core\Endpoints\EndpointFactory;
use Tiknil\Backups\Core\Endpoints\RcloneEndpoint;

/**
 * Thin wrapper around the rclone CLI, bound to a source and a destination
 * {@see RcloneEndpoint}.
 *
 * Callers only deal with paths relative to each disk root; remote provisioning
 * and path syntax stay inside the endpoints.
 */
class Rclone
{
    private const BIN = 'rclone';

    public function __construct(
        private readonly RcloneEndpoint $source,
        private readonly RcloneEndpoint $destination,
    ) {}

    /** Build an instance from two configured filesystem disk names. */
    public static function between(string $sourceDisk, string $destinationDisk): self
    {
        return new self(
            self::endpoint($sourceDisk),
            self::endpoint($destinationDisk),
        );
    }

    /**
     * Compare source and destination, returning rclone's combined output.
     *
     * Status codes: '=' identical, '+' only in source, '*' different,
     * '-' only in destination, '!' error.
     *
     * @return array<int, array{status: string, path: string}>
     */
    public function check(
        string $sourcePath = '',
        string $destinationPath = '',
        bool $sizeOnly = true,
    ): array {
        $this->source->prepare();
        $this->destination->prepare();

        $args = [
            'check',
            $this->source->path($sourcePath),
            $this->destination->path($destinationPath),
            '--combined', '-',
        ];

        if ($sizeOnly) {
            $args[] = '--size-only';
        }

        $process = $this->process($args);
        $process->run();

        $changes = $this->parseCombined($process->getOutput()."\n".$process->getErrorOutput());

        $hasErrors = collect($changes)->contains(
            fn (array $change): bool => $change['status'] === '!',
        );

        if ($hasErrors || $process->getExitCode() > 1) {
            throw new \RuntimeException('rclone check failed: '.$process->getErrorOutput());
        }

        return $changes;
    }

    /**
     * Copy new or changed files from source to destination.
     *
     * @param  callable(string): void|null  $onLine  Receives each progress line.
     */
    public function copy(
        string $sourcePath = '',
        string $destinationPath = '',
        ?callable $onLine = null,
        bool $sizeOnly = true,
    ): void {
        $this->source->prepare();
        $this->destination->prepare();

        $args = [
            'copy',
            $this->source->path($sourcePath),
            $this->destination->path($destinationPath),
        ];

        if ($sizeOnly) {
            $args[] = '--size-only';
        }

        $args[] = '--progress';
        $args[] = '--stats-one-line-date';

        $process = $this->process($args);

        $process->run(function (string $type, string $buffer) use ($onLine): void {
            $line = trim($buffer);

            if ($line !== '' && $onLine !== null) {
                $onLine($line);
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('rclone copy failed: '.$process->getErrorOutput());
        }
    }

    /** @return array<int, array{status: string, path: string}> */
    private function parseCombined(string $output): array
    {
        $changes = [];

        foreach (preg_split('/\R/', trim($output)) as $line) {
            if (preg_match('/^([=+*!-])\s+(.+)$/', trim($line), $m) === 1) {
                $changes[] = ['status' => $m[1], 'path' => $m[2]];
            }
        }

        return $changes;
    }

    /** Resolve a disk name to the matching rclone endpoint. */
    private static function endpoint(string $diskName): RcloneEndpoint
    {
        return EndpointFactory::make($diskName)
            ?? throw new \RuntimeException(
                "Disk '{$diskName}' must use the 's3' or 'local' driver for file backup."
            );
    }

    /** @param array<int, string> $args */
    private function process(array $args): Process
    {
        $process = new Process([self::BIN, ...$args], null, RcloneConfig::env());
        $process->setTimeout(null);

        return $process;
    }
}
