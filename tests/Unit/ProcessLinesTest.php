<?php

use Symfony\Component\Process\Process;
use Tiknil\Backups\Core\ProcessLines;

/** A process that prints the given PHP code. */
function phpProcess(string $code): Process
{
    return new Process([PHP_BINARY, '-r', $code]);
}

function linesOf(string $code): array
{
    $lines = [];

    foreach ((new ProcessLines(phpProcess($code)))->lines() as [$stream, $line]) {
        $lines[] = [$stream, $line];
    }

    return $lines;
}

it('yields complete stdout lines', function () {
    expect(linesOf('echo "a\nb\n";'))
        ->toBe([[Process::OUT, 'a'], [Process::OUT, 'b']]);
});

it('flushes a trailing line without newline', function () {
    expect(linesOf('echo "a\nb";'))
        ->toBe([[Process::OUT, 'a'], [Process::OUT, 'b']]);
});

it('keeps only the last segment of a progress redraw', function () {
    expect(linesOf('echo "p1\rp2\rdone\n";'))
        ->toBe([[Process::OUT, 'done']]);
});

it('keeps a partial redraw buffered until the next write', function () {
    expect(linesOf('echo "frame1\r"; usleep(50000); echo "frame2\n";'))
        ->toBe([[Process::OUT, 'frame2']]);
});

it('yields stderr on the error stream', function () {
    expect(linesOf('fwrite(STDERR, "boom\n");'))
        ->toBe([[Process::ERR, 'boom']]);
});

it('drops empty lines', function () {
    expect(linesOf('echo "\n\na\n\n";'))
        ->toBe([[Process::OUT, 'a']]);
});

it('exposes the exit code', function () {
    $stream = new ProcessLines(phpProcess('exit(3);'));

    $stream->run();

    expect($stream->exitCode())->toBe(3);
});

it('returns the exit code from run()', function () {
    expect((new ProcessLines(phpProcess('exit(2);')))->run())->toBe(2);
});

it('hands every line and its stream to run()', function () {
    $seen = [];

    (new ProcessLines(phpProcess('echo "out\n"; fwrite(STDERR, "err\n");')))
        ->run(function (string $line, string $stream) use (&$seen): void {
            $seen[] = [$stream, $line];
        });

    expect($seen)->toContain([Process::OUT, 'out'])->toContain([Process::ERR, 'err']);
});

it('trims the error tail', function () {
    $stream = new ProcessLines(phpProcess('fwrite(STDERR, "  failure  \n");'));

    $stream->run();

    expect($stream->errorTail())->toBe('failure');
});

it('reports -1 when the process never ran', function () {
    expect((new ProcessLines(phpProcess('echo "x";')))->exitCode())->toBe(-1);
});

it('stops the process when the consumer abandons the generator', function () {
    $process = phpProcess('echo "x\n"; sleep(5);');

    foreach ((new ProcessLines($process))->lines() as $line) {
        break;
    }

    expect($process->isRunning())->toBeFalse();
});
