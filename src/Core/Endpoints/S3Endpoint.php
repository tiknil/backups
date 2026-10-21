<?php

namespace Tiknil\Backups\Core\Endpoints;

use Tiknil\Backups\Core\S3Disk;

/**
 * Exposes an {@see S3Disk} to rclone through a remote declared by environment
 * variables.
 *
 * Declaring the remote with RCLONE_CONFIG_<NAME>_<KEY> works on every rclone
 * version: `rclone config create/update --non-interactive` only exists from
 * v1.56, and before that the command prompts for the missing options.
 *
 * Remote names only use [a-z0-9_]: up to v1.56 rclone turns '-' into '_' when
 * building the variable name, from v1.57 it leaves it as is, so a hyphen makes
 * the variable impossible to set from a shell.
 */
class S3Endpoint implements RcloneEndpoint
{
    public function __construct(
        private readonly S3Disk $disk,
    ) {}

    public function driver(): string
    {
        return 's3';
    }

    public function env(): array
    {
        $prefix = 'RCLONE_CONFIG_'.self::envName($this->remoteName()).'_';

        $options = array_filter([
            'TYPE' => 's3',
            'PROVIDER' => 'Other',
            'ENV_AUTH' => 'false',
            'ACCESS_KEY_ID' => $this->disk->config['key'] ?? '',
            'SECRET_ACCESS_KEY' => $this->disk->config['secret'] ?? '',
            'REGION' => $this->disk->config['region'] ?? '',
            'ENDPOINT' => $this->disk->config['endpoint'] ?? '',
            'SESSION_TOKEN' => $this->disk->config['token'] ?? '',
            'FORCE_PATH_STYLE' => empty($this->disk->config['use_path_style_endpoint']) ? '' : 'true',
        ], fn (string $value): bool => $value !== '');

        $env = [];

        foreach ($options as $key => $value) {
            $env[$prefix.$key] = $value;
        }

        return $env;
    }

    public function path(string $path = ''): string
    {
        $full = implode('/', array_filter([
            $this->disk->root,
            trim($path, '/'),
        ], fn (string $segment): bool => $segment !== ''));

        return $this->remoteName().':'.$this->disk->bucket.($full !== '' ? '/'.$full : '');
    }

    /**
     * Stable, collision-free name for this disk. Falls back to a content hash
     * when the disk name is unknown.
     */
    private function remoteName(): string
    {
        if ($this->disk->name === null) {
            return 'tk_'.substr(sha1($this->disk->bucket.'|'.($this->disk->config['endpoint'] ?? '')), 0, 12);
        }

        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $this->disk->name), '_');

        return 'tk_'.$slug.'_'.substr(sha1($this->disk->name), 0, 6);
    }

    /** Mirror rclone's ConfigToEnv(): the remote name is just upper cased. */
    private static function envName(string $remote): string
    {
        return strtoupper($remote);
    }
}
