<?php

use Tests\Support\RecordingS3;
use Tiknil\Backups\Core\S3Disk;

it('builds object keys below the disk root', function () {
    $disk = s3disk(new RecordingS3(fn (): array => []), root: 'backups');

    expect($disk->fullPath('files', 'blog'))->toBe('backups/files/blog')
        ->and($disk->fullPath('/files/', '/blog/'))->toBe('backups/files/blog')
        ->and($disk->fullPath(''))->toBe('backups')
        ->and($disk->fullPath())->toBe('backups');
});

it('builds keys without a root', function () {
    $disk = s3disk(new RecordingS3(fn (): array => []));

    expect($disk->fullPath('files', 'blog'))->toBe('files/blog')
        ->and($disk->fullPath())->toBe('');
});

it('builds listing prefixes with a trailing slash', function () {
    $disk = s3disk(new RecordingS3(fn (): array => []), root: 'backups');

    expect($disk->prefix('files'))->toBe('backups/files/')
        ->and($disk->prefix())->toBe('backups/');
});

it('builds an empty prefix for an empty root', function () {
    $disk = s3disk(new RecordingS3(fn (): array => []));

    expect($disk->prefix())->toBe('');
});

it('trims the root of the disk config', function () {
    $disk = S3Disk::fromConfig([
        'driver' => 's3',
        'region' => 'eu-south-1',
        'bucket' => 'bucket',
        'root' => '/backups/',
        'key' => 'key',
        'secret' => 'secret',
    ], 'backups');

    expect($disk->root)->toBe('backups')
        ->and($disk->bucket)->toBe('bucket')
        ->and($disk->name)->toBe('backups');
});

it('rejects disks that are not s3', function () {
    S3Disk::fromConfig(['driver' => 'local', 'root' => '/tmp']);
})->throws(RuntimeException::class, 'Disk must be an S3 disk.');

it('reads the disk config by name', function () {
    config(['filesystems.disks.backups' => [
        'driver' => 's3',
        'region' => 'eu-south-1',
        'bucket' => 'bucket',
    ]]);

    expect(S3Disk::fromDiskName('backups')->bucket)->toBe('bucket');
});

it('fails on an unknown disk name', function () {
    S3Disk::fromDiskName('missing');
})->throws(RuntimeException::class, "Disk 'missing' is not configured.");
