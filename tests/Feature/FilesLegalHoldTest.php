<?php

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Tests\Support\FakeRclone;
use Tests\Support\RecordingS3;
use Tiknil\Backups\Core\FilesLegalHold;

function holdSourceDir(): string
{
    $dir = sys_get_temp_dir().'/tk-hold-'.bin2hex(random_bytes(6));
    mkdir($dir.'/uploads', 0777, true);

    return $dir;
}

/** A command that only collects the output of the run. */
function holdCommand(BufferedOutput $buffer): Command
{
    $command = new class extends Command
    {
        protected $signature = 'test:hold-files';
    };

    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return $command;
}

function holdRun(FakeRclone $fake, RecordingS3 $handler, array $sources = [['disk' => 'source', 'folder' => 'uploads']]): array
{
    $holds = new FilesLegalHold($sources, s3disk($handler, root: 'backups'), 'files', 8, $fake->binary);

    $buffer = new BufferedOutput;
    $success = $holds->execute(holdCommand($buffer));

    return [$success, $buffer->fetch()];
}

function holdHandler(?callable $resolver = null): RecordingS3
{
    return new RecordingS3($resolver ?? fn (string $name): array => match ($name) {
        'ListObjectsV2' => ['Contents' => [['Key' => 'backups/files/source/uploads/a.txt']]],
        'HeadObject' => ['ObjectLockLegalHoldStatus' => 'OFF'],
        default => [],
    });
}

beforeEach(function () {
    $this->dir = holdSourceDir();
    config(['filesystems.disks.source' => ['driver' => 'local', 'root' => $this->dir]]);
});

afterEach(function () {
    (new Filesystem)->remove($this->dir);
});

it('locks the identical files with their absolute key', function () {
    $fake = FakeRclone::create("= a.txt\n= folder/b.txt\n+ new.txt\n- gone.txt\n");
    $handler = holdHandler();

    [$success] = holdRun($fake, $handler);

    expect($success)->toBeTrue()
        ->and($handler->inputsOf('PutObjectLegalHold'))->toBe([
            ['Bucket' => 'bucket', 'Key' => 'backups/files/source/uploads/a.txt', 'LegalHold' => ['Status' => 'ON']],
            ['Bucket' => 'bucket', 'Key' => 'backups/files/source/uploads/folder/b.txt', 'LegalHold' => ['Status' => 'ON']],
        ]);
});

it('does not write the objects that are already locked', function () {
    $fake = FakeRclone::create("= a.txt\n");
    $handler = holdHandler(fn (string $name): array => match ($name) {
        'ListObjectsV2' => ['Contents' => [['Key' => 'backups/files/source/uploads/a.txt']]],
        'HeadObject' => ['ObjectLockLegalHoldStatus' => 'ON'],
        default => [],
    });

    [$success, $output] = holdRun($fake, $handler);

    expect($success)->toBeTrue()
        ->and($handler->callsOf('HeadObject'))->toHaveCount(1)
        ->and($handler->callsOf('PutObjectLegalHold'))->toBe([])
        ->and($output)->toContain('0 legal holds applied');
});

it('compares the source folder with the destination prefix', function () {
    $fake = FakeRclone::create("= a.txt\n");
    $handler = holdHandler(fn (string $name): array => match ($name) {
        'ListObjectsV2' => ['Contents' => [['Key' => 'backups/files/source/uploads/a.txt']]],
        default => [],
    });

    holdRun($fake, $handler);

    $argv = $fake->lastArgv();

    expect($argv[0])->toBe('check')
        ->and($argv[1])->toBe(realpath($this->dir).'/uploads')
        ->and($argv)->toContain('--combined')
        ->and(str_ends_with($argv[2], 'bucket/backups/files/source/uploads'))->toBeTrue();
});

it('does not run rclone when the source was never uploaded', function () {
    $fake = FakeRclone::create("= a.txt\n");
    $handler = holdHandler(fn (string $name): array => match ($name) {
        'HeadObject' => ['ObjectLockLegalHoldStatus' => 'OFF'],
        default => [],
    });

    [$success, $output] = holdRun($fake, $handler);

    expect($success)->toBeTrue()
        ->and($fake->calls())->toBe([])
        ->and($handler->names())->toBe(['ListObjectsV2'])
        ->and($output)->toContain('nothing uploaded yet');
});

it('fails the run when rclone fails', function () {
    $fake = FakeRclone::create('', 'directory not found', exitCode: 3);
    $handler = holdHandler();

    [$success, $output] = holdRun($fake, $handler);

    expect($success)->toBeFalse()
        ->and($output)->toContain('rclone check failed: directory not found')
        ->and($handler->callsOf('PutObjectLegalHold'))->toBe([]);
});

it('keeps going when a source is not valid', function () {
    $fake = FakeRclone::create("= a.txt\n");
    $handler = holdHandler();
    config(['filesystems.disks.other' => ['driver' => 'ftp', 'root' => $this->dir]]);

    [$success, $output] = holdRun($fake, $handler, [
        ['disk' => 'missing'],
        ['disk' => 'other'],
        ['disk' => 'source', 'folder' => 'uploads'],
    ]);

    expect($success)->toBeFalse()
        ->and($output)->toContain("Disk 'missing' is not configured.")
        ->and($output)->toContain('unsupported driver')
        ->and($handler->inputsOf('PutObjectLegalHold'))->toHaveCount(1);
});

it('warns when no file source is configured', function () {
    config(['tk-backups.file_sources' => []]);

    $this->artisan('backup:hold-files')
        ->expectsOutputToContain('No file sources configured')
        ->assertSuccessful();
});

it('fails when the destination disk is not configured', function () {
    config(['tk-backups.file_sources' => [['disk' => 'source']]]);
    config(['tk-backups.destination_disk' => 'missing']);

    $this->artisan('backup:hold-files')
        ->expectsOutputToContain("Disk 'missing' is not configured.")
        ->assertFailed();
});
