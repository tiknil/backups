<?php

namespace Tiknil\Backups\Core\Endpoints;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tiknil\Backups\Core\RcloneConfig;
use Tiknil\Backups\Core\S3Disk;

/**
 * Exposes an {@see S3Disk} to rclone through a generated s3 remote.
 */
class S3Endpoint implements RcloneEndpoint
{
    private const BIN = 'rclone';

    /** Remotes configured in this process, keyed by remote name. */
    private static array $configured = [];

    public function __construct(
        private readonly S3Disk $disk,
    ) {}

    public function driver(): string
    {
        return 's3';
    }

    public function prepare(): void
    {
        $name = $this->remoteName();

        if (isset(self::$configured[$name])) {
            return;
        }

        self::$configured[$name] = true;

        $options = [
            'provider' => 'Other',
            'env_auth' => 'false',
            'access_key_id' => $this->disk->config['key'] ?? '',
            'secret_access_key' => $this->disk->config['secret'] ?? '',
            'region' => $this->disk->config['region'] ?? '',
            'endpoint' => $this->disk->config['endpoint'] ?? '',
        ];

        if (!empty($this->disk->config['token'])) {
            $options['session_token'] = $this->disk->config['token'];
        }

        if (!empty($this->disk->config['use_path_style_endpoint'])) {
            $options['force_path_style'] = 'true';
        }

        try {
            $this->writeRemote(['create', $name, 's3'], $options);
        } catch (ProcessFailedException) {
            // Remote already exists; update it instead.
            $this->writeRemote(['update', $name], $options);
        }
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
            return 'tk-'.substr(sha1($this->disk->bucket.'|'.($this->disk->config['endpoint'] ?? '')), 0, 12);
        }

        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $this->disk->name), '-');

        return 'tk-'.$slug.'-'.substr(sha1($this->disk->name), 0, 6);
    }

    /** @param array<string, string> $options */
    private function writeRemote(array $command, array $options): void
    {
        $args = [self::BIN, 'config', ...$command, '--non-interactive'];

        foreach ($options as $key => $value) {
            $args[] = "{$key}={$value}";
        }

        $process = new Process($args, null, RcloneConfig::env());
        $process->setTimeout(30);
        $process->mustRun();
    }
}
