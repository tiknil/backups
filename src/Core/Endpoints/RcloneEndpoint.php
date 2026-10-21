<?php

namespace Tiknil\Backups\Core\Endpoints;

/**
 * A filesystem disk exposed to rclone, either as a remote or as the local
 * filesystem.
 *
 * Implementations translate a path relative to the disk root into the path
 * syntax rclone expects, and declare the environment rclone needs to resolve
 * that path.
 */
interface RcloneEndpoint
{
    public function driver(): string;

    /**
     * Environment variables that define this endpoint for rclone.
     *
     * Remotes are declared as RCLONE_CONFIG_<NAME>_<KEY>, so no config file and
     * no `rclone config` call are involved. Local paths declare nothing.
     *
     * @return array<string, string>
     */
    public function env(): array;

    /** Translate a path relative to the disk root into an rclone path. */
    public function path(string $path): string;
}
