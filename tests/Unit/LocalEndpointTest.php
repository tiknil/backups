<?php

use Symfony\Component\Filesystem\Filesystem;
use Tiknil\Backups\Core\Endpoints\LocalEndpoint;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/tk-backups-test-'.uniqid();
    mkdir($this->root.'/uploads', 0777, true);
});

afterEach(function () {
    if (is_dir($this->root)) {
        (new Filesystem)->remove($this->root);
    }
});

function localEndpoint(string $root): LocalEndpoint
{
    return LocalEndpoint::fromConfig('local', ['driver' => 'local', 'root' => $root]);
}

it('resolves the absolute root', function () {
    $endpoint = localEndpoint($this->root);

    expect($endpoint->path())->toBe(realpath($this->root))
        ->and($endpoint->path('uploads'))->toBe(realpath($this->root).'/uploads')
        ->and($endpoint->path('/uploads/'))->toBe(realpath($this->root).'/uploads')
        ->and($endpoint->path('missing/file.txt'))->toBe(realpath($this->root).'/missing/file.txt');
});

it('resolves symlinks to the real path', function () {
    symlink($this->root, $this->root.'-link');

    try {
        expect(localEndpoint($this->root.'-link')->path())->toBe(realpath($this->root));
    } finally {
        unlink($this->root.'-link');
    }
});

it('declares no env and the local driver', function () {
    $endpoint = localEndpoint($this->root);

    expect($endpoint->env())->toBe([])
        ->and($endpoint->driver())->toBe('local');
});

it('rejects a disk without root', function () {
    LocalEndpoint::fromConfig('local', ['driver' => 'local']);
})->throws(RuntimeException::class, "Local disk 'local' has no root configured.");

it('rejects a root that is not a directory', function () {
    localEndpoint($this->root.'/missing');
})->throws(RuntimeException::class, 'is not a readable directory');
