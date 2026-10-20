<?php

namespace Tiknil\Backups\Core;

use Symfony\Component\Process\Process;

/**
 * Streams a process output line by line.
 *
 * Only the trailing partial line of each stream is buffered, so memory stays
 * constant regardless of how much the process prints (rclone emits one line
 * per file). Symfony's internal output buffer is cleared after every read.
 *
 * Lines are terminated by "\n". A bare "\r" redraws the current line, as
 * rclone does for `--progress`: only the last segment of such a line is kept.
 */
final class ProcessLines
{
    /** Bytes of stderr kept for error reporting. */
    private const ERROR_TAIL = 65536;

    private const POLL_INTERVAL_US = 20000;

    private string $errorTail = '';

    /** @var array<string, string> Pending partial line per stream. */
    private array $partial = [Process::OUT => '', Process::ERR => ''];

    public function __construct(
        private readonly Process $process,
    ) {}

    /**
     * Start the process and yield `[stream, line]` pairs as they are read.
     *
     * The process is stopped if the consumer abandons the generator.
     *
     * @return \Generator<int, array{0: string, 1: string}>
     */
    public function lines(): \Generator
    {
        $this->process->start();

        try {
            while (true) {
                $read = $this->pump();

                yield from $this->emit(false);

                if (!$this->process->isRunning()) {
                    break;
                }

                // Only idle when the pipes had nothing to offer.
                if ($read === 0) {
                    usleep(self::POLL_INTERVAL_US);
                }
            }

            // Pick up whatever the process wrote before exiting.
            $this->pump();
            yield from $this->emit(true);
        } finally {
            if ($this->process->isRunning()) {
                $this->process->stop(5);
            }
        }
    }

    /**
     * Run the process to completion, handing every line to the callback.
     *
     * @param  callable(string $line, string $stream): void|null  $onLine
     * @return int Exit code.
     */
    public function run(?callable $onLine = null): int
    {
        foreach ($this->lines() as [$stream, $line]) {
            if ($onLine !== null) {
                $onLine($line, $stream);
            }
        }

        return $this->exitCode();
    }

    /** Last bytes written to stderr, for error messages. */
    public function errorTail(): string
    {
        return trim($this->errorTail);
    }

    /** Exit code of the process, or -1 when it was not collected. */
    public function exitCode(): int
    {
        return $this->process->getExitCode() ?? -1;
    }

    /** Read fresh output from both streams into the partial buffers. */
    private function pump(): int
    {
        // Read and clear one stream at a time: each read may append to the
        // *other* stream's buffer, and clearing that buffer afterwards would
        // silently drop those bytes.
        $out = $this->process->getIncrementalOutput();
        $this->process->clearOutput();

        $err = $this->process->getIncrementalErrorOutput();
        $this->process->clearErrorOutput();

        if ($err !== '') {
            $this->errorTail = substr($this->errorTail.$err, -self::ERROR_TAIL);
        }

        $this->partial[Process::OUT] .= $out;
        $this->partial[Process::ERR] .= $err;

        return strlen($out) + strlen($err);
    }

    /**
     * Emit complete lines, keeping the trailing partial one buffered.
     *
     * @return \Generator<int, array{0: string, 1: string}>
     */
    private function emit(bool $flush): \Generator
    {
        foreach ([Process::OUT, Process::ERR] as $stream) {
            $lines = explode("\n", $this->partial[$stream]);
            $tail = (string) array_pop($lines);

            // Progress redraws never end with "\n": keep only the latest frame
            // so the buffer cannot grow with each redraw.
            $this->partial[$stream] = $flush ? '' : self::lastSegment($tail);

            if ($flush && $tail !== '') {
                $lines[] = $tail;
            }

            foreach ($lines as $line) {
                $line = self::lastSegment($line);

                if ($line !== '') {
                    yield [$stream, $line];
                }
            }
        }
    }

    /** Text after the last carriage return, trimmed. */
    private static function lastSegment(string $line): string
    {
        $cut = strrpos($line, "\r");

        return trim($cut === false ? $line : substr($line, $cut + 1));
    }
}
