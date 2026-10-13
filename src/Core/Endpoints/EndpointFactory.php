<?php

namespace Tiknil\Backups\Core\Endpoints;

use Tiknil\Backups\Core\S3Disk;

/**
 * Builds the rclone endpoint for a filesystem disk.
 *
 * Returns null when the disk uses a driver that file backup does not support,
 * so callers can skip it instead of failing.
 */
final class EndpointFactory
{
    public static function make(string $diskName): ?RcloneEndpoint
    {
        $config = config("filesystems.disks.{$diskName}");

        if (!is_array($config)) {
            throw new \RuntimeException("Disk '{$diskName}' is not configured.");
        }

        return self::fromConfig($diskName, $config);
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(string $diskName, array $config): ?RcloneEndpoint
    {
        return match ($config['driver'] ?? null) {
            's3' => new S3Endpoint(S3Disk::fromConfig($config, $diskName)),
            'local' => LocalEndpoint::fromConfig($diskName, $config),
            default => null,
        };
    }
}
