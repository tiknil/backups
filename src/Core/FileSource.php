<?php

namespace Tiknil\Backups\Core;

/**
 * Un disco sorgente e una sua cartella, con le relative esclusioni.
 *
 * Il layout dei file è definito in un solo punto: `{prefix}/{disk}/{folder}`.
 * Una voce di configurazione con più cartelle produce una sorgente per cartella.
 */
final class FileSource
{
    /**
     * @param  array<int, string>  $excludes  Pattern rclone, relativi alla radice della cartella.
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $folder = '',
        public readonly array $excludes = [],
    ) {}

    /**
     * Espande una voce di `tk-backups.file_sources` in una sorgente per cartella.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, self>
     */
    public static function list(array $config): array
    {
        $disk = $config['disk'] ?? null;

        if (!is_string($disk) || $disk === '') {
            return [];
        }

        $excludes = self::patterns($config['exclude'] ?? []);

        return array_map(
            fn (string $folder): self => new self($disk, $folder, $excludes),
            self::folders($config['folder'] ?? ''),
        );
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

    /**
     * Una cartella (stringa) o una lista di cartelle: la radice del disco se assente.
     *
     * @return array<int, string>
     */
    private static function folders(mixed $value): array
    {
        $folders = is_array($value) ? $value : [$value];
        $folders = array_map(
            fn (mixed $folder): string => is_string($folder) ? trim($folder, '/') : '',
            $folders,
        );

        return array_values(array_unique($folders)) ?: [''];
    }

    /**
     * Un pattern (stringa) o una lista di pattern. I pattern non vengono
     * normalizzati: rclone assegna un significato a '/' iniziale e finale.
     *
     * @return array<int, string>
     */
    private static function patterns(mixed $value): array
    {
        $patterns = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(fn (mixed $pattern): string => is_string($pattern) ? trim($pattern) : '', $patterns),
            fn (string $pattern): bool => $pattern !== '',
        ));
    }
}
