<?php

use Tiknil\Backups\Core\Endpoints\S3Endpoint;
use Tiknil\Backups\Core\S3Disk;

function endpoint(array $config = [], ?string $name = 'backups', string $root = ''): S3Endpoint
{
    return new S3Endpoint(new S3Disk(s3client(), 'bucket', $root, $config, $name));
}

function remoteOf(S3Endpoint $endpoint): string
{
    return explode(':', $endpoint->path(), 2)[0];
}

it('exposes the disk root and bucket in the rclone path', function () {
    $endpoint = endpoint(root: 'backups');

    expect($endpoint->path())->toMatch('/^tk_backups_[0-9a-f]{6}:bucket\/backups$/')
        ->and($endpoint->path('files/blog'))->toMatch('/^tk_backups_[0-9a-f]{6}:bucket\/backups\/files\/blog$/')
        ->and($endpoint->path('/files/blog/'))->toMatch('/^tk_backups_[0-9a-f]{6}:bucket\/backups\/files\/blog$/');
});

it('omits empty path segments', function () {
    expect(endpoint()->path())->toMatch('/^tk_backups_[0-9a-f]{6}:bucket$/');
});

it('builds a remote name rclone can declare through env variables', function () {
    // rclone up to v1.56 rewrites '-' to '_' in the env variable name; a
    // hyphen in the remote would make it impossible to declare.
    $remote = remoteOf(endpoint(name: 'my-disk.prod'));

    expect($remote)->toMatch('/^tk_my_disk_prod_[0-9a-f]{6}$/')
        ->and($remote)->not->toContain('-')
        ->and($remote)->not->toContain('.');
});

it('keeps the remote name stable and distinct per disk', function () {
    $first = remoteOf(endpoint(name: 'one'));
    $again = remoteOf(endpoint(name: 'one'));
    $other = remoteOf(endpoint(name: 'two'));

    expect($first)->toBe($again)
        ->and($first)->not->toBe($other);
});

it('falls back to a content hash when the disk name is unknown', function () {
    $remote = remoteOf(endpoint(['endpoint' => 'http://minio:9000'], name: null));

    expect($remote)->toMatch('/^tk_[0-9a-f]{12}$/')
        ->and($remote)->toBe(remoteOf(endpoint(['endpoint' => 'http://minio:9000'], name: null)))
        ->and($remote)->not->toBe(remoteOf(endpoint(['endpoint' => 'http://other:9000'], name: null)));
});

it('declares every configured option as an env variable', function () {
    $endpoint = endpoint([
        'key' => 'key',
        'secret' => 'secret',
        'region' => 'eu-south-1',
        'endpoint' => 'http://minio:9000',
        'token' => 'token',
        'use_path_style_endpoint' => true,
    ]);

    $prefix = 'RCLONE_CONFIG_'.strtoupper(remoteOf($endpoint)).'_';

    expect($endpoint->env())->toBe([
        $prefix.'TYPE' => 's3',
        $prefix.'PROVIDER' => 'Other',
        $prefix.'ENV_AUTH' => 'false',
        $prefix.'ACCESS_KEY_ID' => 'key',
        $prefix.'SECRET_ACCESS_KEY' => 'secret',
        $prefix.'REGION' => 'eu-south-1',
        $prefix.'ENDPOINT' => 'http://minio:9000',
        $prefix.'SESSION_TOKEN' => 'token',
        $prefix.'FORCE_PATH_STYLE' => 'true',
    ]);
});

it('omits unset options and only forces path style when requested', function () {
    $prefix = 'RCLONE_CONFIG_'.strtoupper(remoteOf(endpoint(['use_path_style_endpoint' => false]))).'_';

    expect(endpoint(['use_path_style_endpoint' => false])->env())->toBe([
        $prefix.'TYPE' => 's3',
        $prefix.'PROVIDER' => 'Other',
        $prefix.'ENV_AUTH' => 'false',
    ]);
});

it('reports the s3 driver', function () {
    expect(endpoint()->driver())->toBe('s3');
});
