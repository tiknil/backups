<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Tests\Support\FakeRclone;
use Tiknil\Backups\Core\Endpoints\LocalEndpoint;
use Tiknil\Backups\Core\Endpoints\S3Endpoint;
use Tiknil\Backups\Core\Rclone;
use Tiknil\Backups\Core\S3Disk;

const COMBINED_REPORT = <<<'OUT'
= same.txt
+ added.txt
* changed.txt
- removed.txt
OUT;

function rcloneEnvDir(): string
{
    $dir = sys_get_temp_dir().'/tk-rclone-'.bin2hex(random_bytes(6));
    mkdir($dir);

    return $dir;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/tk-rclone-*') ?: [] as $dir) {
        (new Filesystem)->remove($dir);
    }
});

it('reports only the entries that differ', function () {
    $fake = FakeRclone::create(COMBINED_REPORT."\n");

    expect(iterator_to_array(rclone($fake)->check()))->toBe([
        ['status' => '+', 'path' => 'added.txt'],
        ['status' => '*', 'path' => 'changed.txt'],
        ['status' => '-', 'path' => 'removed.txt'],
    ]);
});

it('hands the identical entries to the callback', function () {
    $fake = FakeRclone::create("= a.txt\n= b.txt\n+ c.txt\n");

    $same = [];
    $changes = rclone($fake)->check(onIdentical: function (array $change) use (&$same): void {
        $same[] = $change['path'];
    });

    expect($same)->toBe(['a.txt', 'b.txt'])
        ->and(iterator_to_array($changes))->toBe([['status' => '+', 'path' => 'c.txt']]);
});

it('writes large checks to disk with bounded memory', function () {
    $fake = FakeRclone::create(str_repeat("+ added.txt\n- removed.txt\n", 50000));
    $rclone = rclone($fake);
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $report = $rclone->check();
    $changed = 0;
    foreach ($report->changed() as $path) {
        $changed++;
    }

    expect($report->countChanged())->toBe(50000)
        ->and($report->countDeleted())->toBe(50000)
        ->and($changed)->toBe(50000)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(12 * 1024 * 1024);
});

it('returns nothing when everything is identical', function () {
    $fake = FakeRclone::create("= a.txt\n= b.txt\n");

    expect(iterator_to_array(rclone($fake)->check()))->toBe([]);
});

it('tolerates a report without newline at the end', function () {
    $fake = FakeRclone::create('+ last.txt');

    expect(iterator_to_array(rclone($fake)->check()))->toBe([['status' => '+', 'path' => 'last.txt']]);
});

it('keeps only the last frame of a progress redraw', function () {
    $fake = FakeRclone::create("+ first.txt\r+ second.txt\n");

    expect(iterator_to_array(rclone($fake)->check()))->toBe([['status' => '+', 'path' => 'second.txt']]);
});

it('ignores lines that are not entries', function () {
    $fake = FakeRclone::create("\n2026/01/01 10:00:00 NOTICE: something\n+ ok.txt\n");

    expect(iterator_to_array(rclone($fake)->check()))->toBe([['status' => '+', 'path' => 'ok.txt']]);
});

it('does not fail when rclone reports differences through the exit code', function () {
    // rclone check exits 1 when the trees differ: that is not an error.
    $fake = FakeRclone::create("+ a.txt\n", exitCode: 1);

    expect(iterator_to_array(rclone($fake)->check()))->toBe([['status' => '+', 'path' => 'a.txt']]);
});

it('fails on an error entry', function () {
    $fake = FakeRclone::create("+ a.txt\n! broken.txt\n", 'permission denied');

    expect(fn () => rclone($fake)->check())
        ->toThrow(RuntimeException::class, 'rclone check failed: permission denied');
});

it('fails on an unexpected exit code', function () {
    $fake = FakeRclone::create('+ a.txt', 'fatal error', exitCode: 3);

    expect(fn () => rclone($fake)->check())
        ->toThrow(RuntimeException::class, 'rclone check failed: fatal error');
});

it('asks rclone to compare the two paths with a combined report', function () {
    $fake = FakeRclone::create();

    rclone($fake)->check('files', 'backup/files');

    expect($fake->lastArgv())->toBe([
        'check',
        '/source/files',
        '/destination/backup/files',
        '--combined',
        '-',
    ]);
});

it('copies the two paths with progress', function () {
    $fake = FakeRclone::create();

    rclone($fake)->copy('files', 'backup/files');

    expect($fake->lastArgv())->toBe([
        'copy',
        '/source/files',
        '/destination/backup/files',
        '--progress',
        '--stats-one-line-date',
        '--transfers=16',
    ]);
});

it('runs the configured number of parallel transfers', function () {
    config(['tk-backups.transfer_concurrency' => 4]);

    $fake = FakeRclone::create();
    rclone($fake)->copy();

    expect($fake->lastArgv())->toContain('--transfers=4')->not->toContain('--transfers=16');
});

it('lets the caller override the transfer concurrency', function () {
    config(['tk-backups.transfer_concurrency' => 4]);

    $fake = FakeRclone::create();
    rclone($fake, transfers: 2)->copy();

    expect($fake->lastArgv())->toContain('--transfers=2');
});

it('never runs with fewer than one transfer', function () {
    config(['tk-backups.transfer_concurrency' => 0]);

    $fake = FakeRclone::create();
    rclone($fake)->copy();

    expect($fake->lastArgv())->toContain('--transfers=1');
});

it('streams the copy output to the caller', function () {
    $fake = FakeRclone::create("Transferred: 1 / 1\nElapsed time: 1s\n");

    $lines = [];
    rclone($fake)->copy(onLine: function (string $line) use (&$lines): void {
        $lines[] = $line;
    });

    expect($lines)->toBe(['Transferred: 1 / 1', 'Elapsed time: 1s']);
});

it('fails when the copy fails', function () {
    $fake = FakeRclone::create('', 'directory not found', exitCode: 1);

    expect(fn () => rclone($fake)->copy())
        ->toThrow(RuntimeException::class, 'rclone copy failed: directory not found');
});

it('passes the rclone config and the endpoint env to the process', function () {
    $dir = rcloneEnvDir();

    $disk = new S3Disk(s3client(), 'bucket', 'root', [
        'key' => 'key',
        'secret' => 'secret',
        'region' => 'eu-south-1',
        'endpoint' => 'http://minio:9000',
    ], 'backups');

    $fake = FakeRclone::create();
    rclone($fake, new LocalEndpoint($dir), new S3Endpoint($disk))->copy();

    $env = $fake->calls()[0]['env'];
    $prefix = 'RCLONE_CONFIG_'.strtoupper(explode(':', $fake->lastArgv()[2])[0]).'_';

    expect($env)->toHaveKey('RCLONE_CONFIG')
        ->and($env[$prefix.'TYPE'])->toBe('s3')
        ->and($env[$prefix.'PROVIDER'])->toBe('Other')
        ->and($env[$prefix.'ACCESS_KEY_ID'])->toBe('key')
        ->and($env[$prefix.'SECRET_ACCESS_KEY'])->toBe('secret')
        ->and($env[$prefix.'REGION'])->toBe('eu-south-1')
        ->and($env[$prefix.'ENDPOINT'])->toBe('http://minio:9000')
        ->and($fake->lastArgv()[1])->toBe($dir);
});

it('probes the disk listing its root', function () {
    $dir = rcloneEnvDir();
    config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $dir]]);

    $fake = FakeRclone::create();
    Rclone::probe('local', $fake->binary);

    expect($fake->lastArgv())->toBe(['lsd', realpath($dir), '--retries', '1']);
});

it('fails the probe when rclone cannot reach the disk', function () {
    $dir = rcloneEnvDir();
    config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $dir]]);

    $fake = FakeRclone::create('', 'credentials expired', exitCode: 1);

    expect(fn () => Rclone::probe('local', $fake->binary))
        ->toThrow(ProcessFailedException::class);
});

it('refuses a disk that is not configured', function () {
    Rclone::between('missing', 'missing');
})->throws(RuntimeException::class, "Disk 'missing' is not configured.");

it('refuses a disk with an unsupported driver', function () {
    config([
        'filesystems.disks.source' => ['driver' => 'ftp', 'root' => '/tmp'],
        'filesystems.disks.target' => ['driver' => 'local', 'root' => sys_get_temp_dir()],
    ]);

    Rclone::between('source', 'target');
})->throws(RuntimeException::class, "Disk 'source' must use the 's3' or 'local' driver for file backup.");

it('builds both endpoints from the disk names', function () {
    $source = rcloneEnvDir();
    $target = rcloneEnvDir();

    config([
        'filesystems.disks.source' => ['driver' => 'local', 'root' => $source],
        'filesystems.disks.target' => ['driver' => 'local', 'root' => $target],
    ]);

    $fake = FakeRclone::create();

    Rclone::between('source', 'target', $fake->binary)->copy();

    expect($fake->lastArgv())->toBe([
        'copy',
        realpath($source),
        realpath($target),
        '--progress',
        '--stats-one-line-date',
        '--transfers=16',
    ]);
});
