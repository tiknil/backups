<?php

namespace Tiknil\Backups\Core;

/**
 * Un disco sorgente configurato e la sua posizione nel bucket di backup.
 *
 * Il layout dei file è definito in un solo punto: `{prefix}/{disk}/{folder}`.
 */
final class FileSource
{
    public function __construct(
        public readonly string $disk,
        public readonly string $folder = '',
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): ?self
    {
        $disk = $config['disk'] ?? null;

        if (!is_string($disk) || $disk === '') {
            return null;
        }

        $folder = $config['folder'] ?? '';

        return new self($disk, is_string($folder) ? trim($folder, '/') : '');
    }

    /** Scope leggibile, es. "s3/blog". */
    public function scope(): string
    {
        return $this->folder === '' ? $this->disk : $this->disk.'/'.$this->folder;
    }

    /** Percorso nel bucket di destinazione, es. "files/s3/blog". */
    public function destinationPath(string $prefix): string
    {
        return trim(implode('/', array_filter([
            trim($prefix, '/'),
            $this->disk,
            $this->folder,
        ], fn (string $part): bool => $part !== '')), '/');
    }
}
