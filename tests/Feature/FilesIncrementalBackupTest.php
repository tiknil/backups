<?php

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FakeRclone;
use Tests\Support\RecordingS3;
use Tiknil\Backups\Core\FilesIncrementalBackup;
use Tiknil\Backups\Core\FileSource;
use Tiknil\Backups\Core\ObjectLock;

function runFileBackup(FakeRclone $fake, RecordingS3 $handler): array
{
    $disk = s3disk($handler, root: 'root');
    $backup = new FilesIncrementalBackup([], 'backups', 'files');
    $command = new Command;
    $command->setLaravel(app());
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return (new ReflectionMethod($backup, 'backupSource'))->invoke(
        $backup,
        new FileSource('storage', 'uploads', ['*.tmp']),
        rclone($fake),
        $disk,
        new ObjectLock($disk),
        $command,
    );
}

it('protects and expires files after a single copy', function () {
    $fake = FakeRclone::create("= same.txt\n+ new.txt\n* changed.txt\n- removed.txt\n");
    $handler = new RecordingS3(fn (): array => []);

    $counts = runFileBackup($fake, $handler);

    expect($counts)->toBe(['changed' => 2, 'deleted' => 1])
        ->and($fake->calls())->toHaveCount(1)
        ->and($fake->lastArgv())->toContain('copy', '--combined', '--exclude=*.tmp')
        ->not->toContain('check')
        ->and($handler->inputsOf('PutObjectLegalHold'))->toBe([
            ['Bucket' => 'bucket', 'Key' => 'root/files/storage/uploads/new.txt', 'LegalHold' => ['Status' => 'ON']],
            ['Bucket' => 'bucket', 'Key' => 'root/files/storage/uploads/changed.txt', 'LegalHold' => ['Status' => 'ON']],
            ['Bucket' => 'bucket', 'Key' => 'root/files/storage/uploads/removed.txt', 'LegalHold' => ['Status' => 'OFF']],
        ])
        ->and($handler->inputsOf('PutObjectRetention')[0]['Retention']['Mode'])->toBe('COMPLIANCE');
});

it('does not update locks or retention when the copy fails', function () {
    $fake = FakeRclone::create("+ new.txt\n- removed.txt\n", 'transfer failed', exitCode: 1);
    $handler = new RecordingS3(fn (): array => []);

    expect(fn () => runFileBackup($fake, $handler))->toThrow(RuntimeException::class, 'rclone copy failed')
        ->and($handler->calls)->toBe([]);
});

it('does not update identical files', function () {
    $fake = FakeRclone::create("= same.txt\n");
    $handler = new RecordingS3(fn (): array => []);

    expect(runFileBackup($fake, $handler))->toBe(['changed' => 0, 'deleted' => 0])
        ->and($fake->calls())->toHaveCount(1)
        ->and($handler->calls)->toBe([]);
});
