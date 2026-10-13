<?php

namespace Tiknil\Backups\Core\Endpoints;

/**
 * A filesystem disk exposed to rclone, either as a remote or as the local
 * filesystem.
 *
 * Implementations translate a path relative to the disk root into the path
 * syntax rclone expects, and provision any remote they require.
 */
interface RcloneEndpoint
{
    public function driver(): string;

    /** Make the endpoint usable by rclone (e.g. create its remote). */
    public function prepare(): void;

    /** Translate a path relative to the disk root into an rclone path. */
    public function path(string $path): string;
}
