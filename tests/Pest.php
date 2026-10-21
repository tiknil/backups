<?php

use Aws\S3\S3Client;
use Tests\Support\FakeRclone;
use Tests\Support\RecordingS3;
use Tests\Support\StubEndpoint;
use Tests\TestCase;
use Tiknil\Backups\Core\Endpoints\RcloneEndpoint;
use Tiknil\Backups\Core\Rclone;
use Tiknil\Backups\Core\S3Disk;

uses(TestCase::class)->in('Unit', 'Feature');

afterEach(fn () => FakeRclone::cleanup())->in('Feature');

/** An S3 client that never reaches the network. */
function s3client(?RecordingS3 $handler = null): S3Client
{
    return new S3Client([
        'version' => 'latest',
        'region' => 'eu-south-1',
        'credentials' => ['key' => 'key', 'secret' => 'secret'],
        'handler' => $handler ?? new RecordingS3(fn (): array => []),
    ]);
}

function s3disk(RecordingS3 $handler, string $bucket = 'bucket', string $root = ''): S3Disk
{
    return new S3Disk(s3client($handler), $bucket, $root);
}

/** An Rclone bound to the given fake binary. */
function rclone(
    FakeRclone $fake,
    ?RcloneEndpoint $source = null,
    ?RcloneEndpoint $destination = null,
    ?int $transfers = null,
): Rclone {
    return new Rclone(
        $source ?? new StubEndpoint('/source'),
        $destination ?? new StubEndpoint('/destination'),
        $fake->binary,
        $transfers,
    );
}
