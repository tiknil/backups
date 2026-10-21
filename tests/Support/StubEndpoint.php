<?php

namespace Tests\Support;

use Tiknil\Backups\Core\Endpoints\RcloneEndpoint;

/** Endpoint with a fixed path and env, to assert exactly what rclone receives. */
final class StubEndpoint implements RcloneEndpoint
{
    /**
     * @param  array<string, string>  $env
     */
    public function __construct(
        private readonly string $path = '/stub/path',
        private readonly array $env = [],
        private readonly string $driver = 'stub',
    ) {}

    public function driver(): string
    {
        return $this->driver;
    }

    public function env(): array
    {
        return $this->env;
    }

    public function path(string $path): string
    {
        return rtrim($this->path, '/').($path === '' ? '' : '/'.trim($path, '/'));
    }
}
