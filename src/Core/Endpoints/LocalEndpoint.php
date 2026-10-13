<?php

namespace Tiknil\Backups\Core\Endpoints;

/**
 * Exposes a Laravel "local" disk to rclone as a plain filesystem path.
 *
 * rclone reads local paths directly, so no remote is required.
 */
class LocalEndpoint implements RcloneEndpoint
{
    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        $root = $config['root'] ?? '';

        if (!is_string($root) || $root === '') {
            throw new \RuntimeException("Local disk '{$name}' has no root configured.");
        }

        $real = realpath($root);

        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("Local disk '{$name}' root '{$root}' is not a readable directory.");
        }

        return new self($real);
    }

    public function driver(): string
    {
        return 'local';
    }

    public function prepare(): void
    {
        // Local disks need no remote.
    }

    public function path(string $path = ''): string
    {
        $relative = trim($path, '/');
        $root = rtrim($this->root, '/');

        if ($relative === '') {
            return $root === '' ? '/' : $root;
        }

        return ($root === '' ? '' : $root).'/'.$relative;
    }
}
