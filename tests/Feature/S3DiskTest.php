<?php

use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Tests\Support\RecordingS3;
use Tiknil\Backups\Core\RetentionPeriod;

beforeEach(function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function s3Pair(callable $resolver, string $root = 'backups'): array
{
    $handler = new RecordingS3($resolver);

    return [s3disk($handler, root: $root), $handler];
}

it('pings the bucket', function () {
    [$disk, $s3] = s3Pair(fn (): array => []);

    $disk->ping();

    expect($s3->inputsOf('ListObjectsV2'))->toBe([['Bucket' => 'bucket', 'MaxKeys' => 1]]);
});

it('reads one tag', function () {
    [$disk] = s3Pair(fn (): array => [
        'TagSet' => [['Key' => 'a', 'Value' => '1'], ['Key' => 'b', 'Value' => '2']],
    ]);

    expect($disk->tag('backups/file.zip', 'b'))->toBe('2')
        ->and($disk->tag('backups/file.zip', 'missing'))->toBeNull();
});

it('adds a tag while preserving the others', function () {
    [$disk, $s3] = s3Pair(fn (): array => ['TagSet' => [['Key' => 'keep', 'Value' => 'yes']]]);

    $disk->setTag('backups/file.zip', 'backup-deleted-at', 'now');

    expect($s3->inputsOf('PutObjectTagging')[0]['Tagging']['TagSet'])->toBe([
        ['Key' => 'keep', 'Value' => 'yes'],
        ['Key' => 'backup-deleted-at', 'Value' => 'now'],
    ]);
});

it('updates an existing tag without duplicating it', function () {
    [$disk, $s3] = s3Pair(fn (): array => [
        'TagSet' => [['Key' => 'tag', 'Value' => 'old']],
    ]);

    $disk->setTag('backups/file.zip', 'tag', 'new');

    expect($s3->inputsOf('PutObjectTagging')[0]['Tagging']['TagSet'])
        ->toBe([['Key' => 'tag', 'Value' => 'new']]);
});

it('removes a tag when the value is null', function () {
    [$disk, $s3] = s3Pair(fn (): array => [
        'TagSet' => [['Key' => 'tag', 'Value' => 'old'], ['Key' => 'keep', 'Value' => 'yes']],
    ]);

    $disk->setTag('backups/file.zip', 'tag', null);

    expect($s3->inputsOf('PutObjectTagging')[0]['Tagging']['TagSet'])
        ->toBe([['Key' => 'keep', 'Value' => 'yes']]);
});

it('applies retention when the object has none', function () {
    [$disk, $s3] = s3Pair(fn (): array => []);

    $disk->retainFor('backups/file.zip', 30);

    expect($s3->inputsOf('PutObjectRetention')[0])->toBe([
        'Bucket' => 'bucket',
        'Key' => 'backups/file.zip',
        'Retention' => [
            'Mode' => 'COMPLIANCE',
            'RetainUntilDate' => '2026-01-31T10:00:00Z',
        ],
    ]);
});

it('does not shorten an existing retention', function () {
    [$disk, $s3] = s3Pair(fn (): array => [
        'ObjectLockRetainUntilDate' => '2026-03-31T10:00:00Z',
    ]);

    $disk->retainFor('backups/file.zip', 30);

    expect($s3->names())->toBe(['HeadObject']);
});

it('extends an existing retention that expires earlier', function () {
    [$disk, $s3] = s3Pair(fn (): array => [
        'ObjectLockRetainUntilDate' => '2026-01-02T10:00:00Z',
    ]);

    $disk->retainFor('backups/file.zip', 30);

    expect($s3->names())->toBe(['HeadObject', 'PutObjectRetention']);
});

it('applies the compliance retention and the backup class of a period', function () {
    [$disk, $s3] = s3Pair(fn (): array => []);

    $disk->applyRetention('backups/file.zip', RetentionPeriod::Daily);

    expect($s3->inputsOf('PutObjectRetention')[0]['Retention'])->toBe([
        'Mode' => 'COMPLIANCE',
        'RetainUntilDate' => '2026-01-09T23:59:59Z',
    ])
        // The tag carries the enum case name, not its value ('daily').
        ->and($s3->inputsOf('PutObjectTagging')[0]['Tagging']['TagSet'])
        ->toBe([['Key' => 'backup-class', 'Value' => 'Daily']]);
});

it('lists keys across pages', function () {
    [$disk, $s3] = s3Pair(fn (string $name, array $input): array => match ($input['ContinuationToken'] ?? null) {
        null => ['Contents' => [['Key' => 'backups/a'], ['Key' => 'backups/b']], 'IsTruncated' => true, 'NextContinuationToken' => 'next'],
        'next' => ['Contents' => [['Key' => 'backups/c']], 'IsTruncated' => false],
    });

    expect($disk->keys('daily'))->toBe(['backups/a', 'backups/b', 'backups/c'])
        ->and($s3->inputsOf('ListObjectsV2')[0]['Prefix'])->toBe('backups/daily/');
});

it('detects an empty prefix', function () {
    [$disk, $s3] = s3Pair(fn (): array => []);

    expect($disk->isEmpty('daily'))->toBeTrue()
        ->and($disk->firstKey('daily'))->toBeNull()
        ->and($s3->inputsOf('ListObjectsV2')[0])->toBe([
            'Bucket' => 'bucket',
            'Prefix' => 'backups/daily/',
            'MaxKeys' => 1,
        ]);
});

it('returns the first key of a prefix', function () {
    [$disk] = s3Pair(fn (): array => ['Contents' => [['Key' => 'backups/daily/file.zip']]]);

    expect($disk->firstKey('daily'))->toBe('backups/daily/file.zip')
        ->and($disk->isEmpty('daily'))->toBeFalse();
});

it('checks the existence of an object', function (callable $resolver, bool $expected) {
    [$disk] = s3Pair($resolver);

    expect($disk->exists('backups/file.zip'))->toBe($expected);
})->with([
    'present' => [fn (): array => [], true],
    'missing' => [fn (string $name, array $input, $command) => new S3Exception(
        'Not Found',
        $command,
        ['response' => new Response(404)],
    ), false],
]);

it('copies an object inside the bucket', function () {
    [$disk, $s3] = s3Pair(fn (): array => []);

    $disk->copy('backups/daily/file.zip', 'backups/weekly/file.zip');

    expect($s3->inputsOf('CopyObject')[0])->toBe([
        'Bucket' => 'bucket',
        'Key' => 'backups/weekly/file.zip',
        'CopySource' => 'bucket/backups/daily/file.zip',
    ]);
});

it('toggles the legal hold', function (bool $enabled, string $status) {
    [$disk, $s3] = s3Pair(fn (): array => []);

    $disk->legalHold('backups/file.zip', $enabled);

    expect($s3->inputsOf('PutObjectLegalHold')[0]['LegalHold'])->toBe(['Status' => $status]);
})->with([
    'on' => [true, 'ON'],
    'off' => [false, 'OFF'],
]);
