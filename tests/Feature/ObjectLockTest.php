<?php

use Illuminate\Support\Carbon;
use Tests\Support\RecordingS3;
use Tiknil\Backups\Core\ObjectLock;

beforeEach(function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function lockPair(callable $resolver, int $concurrency = 8): array
{
    $handler = new RecordingS3($resolver);

    return [new ObjectLock(s3disk($handler), $concurrency), $handler];
}

it('locks changed objects and drops their deletion marker', function () {
    [$lock, $s3] = lockPair(fn (): array => [
        'TagSet' => [['Key' => ObjectLock::DELETED_TAG, 'Value' => '2025-12-01T00:00:00Z']],
    ]);

    $done = [];
    $lock->protect(['backups/a', 'backups/b'], function (string $key) use (&$done): void {
        $done[] = $key;
    });

    expect($done)->toBe(['backups/a', 'backups/b'])
        ->and($s3->inputsOf('PutObjectLegalHold'))->toBe([
            ['Bucket' => 'bucket', 'Key' => 'backups/a', 'LegalHold' => ['Status' => 'ON']],
            ['Bucket' => 'bucket', 'Key' => 'backups/b', 'LegalHold' => ['Status' => 'ON']],
        ])
        ->and($s3->inputsOf('PutObjectTagging')[0]['Tagging']['TagSet'])->toBe([]);
});

it('releases a deleted object that is already marked', function () {
    [$lock, $s3] = lockPair(fn (): array => [
        'TagSet' => [['Key' => ObjectLock::DELETED_TAG, 'Value' => '2025-12-01T00:00:00Z']],
    ]);

    $lock->expire(['backups/a']);

    expect($s3->names())->toBe(['GetObjectTagging', 'PutObjectLegalHold'])
        ->and($s3->inputsOf('PutObjectLegalHold')[0]['LegalHold'])->toBe(['Status' => 'OFF']);
});

it('marks an unmarked deleted object before releasing it', function () {
    [$lock, $s3] = lockPair(fn (string $name): array => match ($name) {
        'HeadObject' => ['ObjectLockRetainUntilDate' => '2026-01-02T10:00:00Z'],
        'GetObjectTagging' => ['TagSet' => [['Key' => 'other', 'Value' => 'x']]],
        default => [],
    });

    $lock->expire(['backups/a']);

    expect($s3->names())->toBe([
        'GetObjectTagging',
        'HeadObject',
        'PutObjectRetention',
        'GetObjectTagging',
        'PutObjectTagging',
        'PutObjectLegalHold',
    ])
        ->and($s3->inputsOf('PutObjectRetention')[0]['Retention'])->toBe([
            'Mode' => 'COMPLIANCE',
            'RetainUntilDate' => '2026-01-31T10:00:00Z',
        ])
        ->and($s3->inputsOf('PutObjectTagging')[0]['Tagging']['TagSet'])->toBe([
            ['Key' => 'other', 'Value' => 'x'],
            ['Key' => ObjectLock::DELETED_TAG, 'Value' => '2026-01-01T10:00:00+00:00'],
        ])
        ->and($s3->inputsOf('PutObjectLegalHold')[0]['LegalHold'])->toBe(['Status' => 'OFF']);
});

it('does not shorten the retention of a deleted object', function () {
    [$lock, $s3] = lockPair(fn (string $name): array => match ($name) {
        'HeadObject' => ['ObjectLockRetainUntilDate' => '2026-06-01T10:00:00Z'],
        default => [],
    });

    $lock->expire(['backups/a']);

    expect($s3->names())->not->toContain('PutObjectRetention');
});

it('reports every key once, even when some fail', function () {
    [$lock, $s3] = lockPair(function (string $name, array $input) {
        if ($name === 'PutObjectLegalHold' && $input['Key'] === 'backups/bad') {
            throw new RuntimeException('boom');
        }

        return [];
    });

    $done = [];

    $run = function () use ($lock, &$done): void {
        $lock->protect(['backups/ok', 'backups/bad', 'backups/also-ok'], function (string $key) use (&$done): void {
            $done[] = $key;
        });
    };

    expect($run)->toThrow(RuntimeException::class, '1 object lock operations failed');

    expect($done)->toBe(['backups/ok', 'backups/also-ok'])
        ->and($s3->inputsOf('PutObjectLegalHold'))->toHaveCount(3);
});

it('processes every key at the configured concurrency', function () {
    [$lock, $s3] = lockPair(fn (): array => [], concurrency: 2);

    $lock->protect(['backups/a', 'backups/b', 'backups/c', 'backups/d']);

    expect($s3->inputsOf('PutObjectLegalHold'))->toHaveCount(4);
});
