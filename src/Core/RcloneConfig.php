<?php

namespace Tiknil\Backups\Core;

/**
 * Location of an isolated rclone configuration file for this process.
 *
 * Remotes are declared through environment variables, so this file stays empty:
 * it only keeps rclone from reading (and writing) ~/.config/rclone/rclone.conf.
 */
final class RcloneConfig
{
    private static ?string $path = null;

    public static function path(): string
    {
        if (self::$path === null) {
            $path = tempnam(sys_get_temp_dir(), 'tk-rclone-');

            if ($path === false) {
                throw new \RuntimeException('Could not create a temporary rclone configuration file.');
            }

            self::$path = $path;

            register_shutdown_function(static function () use ($path): void {
                if (is_file($path)) {
                    @unlink($path);
                }
            });
        }

        return self::$path;
    }

    /** @return array<string, string> */
    public static function env(): array
    {
        return ['RCLONE_CONFIG' => self::path()];
    }
}
