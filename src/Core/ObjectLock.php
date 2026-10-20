<?php

namespace Tiknil\Backups\Core;

use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Throwable;

/**
 * Applies legal holds, retention and deletion markers to many objects at once.
 *
 * Each object is handled by a chain of asynchronous S3 calls: operations on the
 * same object stay ordered, while different objects overlap. The number of
 * requests in flight is bounded by the configured concurrency.
 */
class ObjectLock
{
    public const DELETED_TAG = 'backup-deleted-at';

    public const DELETED_RETENTION_DAYS = 30;

    public function __construct(
        private readonly S3Disk $disk,
        private readonly int $concurrency = 8,
    ) {}

    /**
     * Lock changed objects and clear their deletion marker.
     *
     * @param  iterable<string>  $keys  Absolute object keys.
     * @param  callable(string $key): void|null  $onDone
     */
    public function protect(iterable $keys, ?callable $onDone = null): void
    {
        $this->pool(
            $keys,
            fn (string $key): PromiseInterface => $this->disk->legalHoldAsync($key, true)
                ->then(fn () => $this->disk->setTagAsync($key, self::DELETED_TAG, null)),
            $onDone,
        );
    }

    /**
     * Release objects deleted on the source: extend their retention, then drop
     * the legal hold so the lifecycle rule can expire them.
     *
     * @param  iterable<string>  $keys  Absolute object keys.
     * @param  callable(string $key): void|null  $onDone
     */
    public function expire(iterable $keys, ?callable $onDone = null): void
    {
        $this->pool(
            $keys,
            fn (string $key): PromiseInterface => $this->disk->tagAsync($key, self::DELETED_TAG)
                ->then(function (?string $marked) use ($key): PromiseInterface {
                    if ($marked !== null) {
                        return $this->disk->legalHoldAsync($key, false);
                    }

                    return $this->disk->retainForAsync($key, self::DELETED_RETENTION_DAYS)
                        ->then(fn () => $this->disk->setTagAsync(
                            $key,
                            self::DELETED_TAG,
                            now('UTC')->toIso8601String(),
                        ))
                        ->then(fn () => $this->disk->legalHoldAsync($key, false));
                }),
            $onDone,
        );
    }

    /**
     * Run one promise chain per key, at most `$concurrency` at a time.
     *
     * Every key is attempted: a failure does not stop the others, but the
     * whole batch throws at the end so the run cannot report success.
     *
     * @param  iterable<string>  $keys
     * @param  callable(string): PromiseInterface  $run
     * @param  callable(string): void|null  $onDone
     */
    private function pool(iterable $keys, callable $run, ?callable $onDone): void
    {
        $failures = [];

        $promises = (function () use ($keys, $run): \Generator {
            foreach ($keys as $key) {
                yield $key => $run($key);
            }
        })();

        (new EachPromise($promises, [
            'concurrency' => max(1, $this->concurrency),
            'fulfilled' => function ($value, string $key) use ($onDone): void {
                if ($onDone !== null) {
                    $onDone($key);
                }
            },
            'rejected' => function ($reason, string $key) use (&$failures): void {
                $failures[$key] = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;
            },
        ]))->promise()->wait();

        if ($failures !== []) {
            throw new \RuntimeException($this->summary($failures));
        }
    }

    /** @param  array<string, string>  $failures */
    private function summary(array $failures): string
    {
        $lines = [];

        foreach (array_slice($failures, 0, 10, true) as $key => $message) {
            $lines[] = "{$key}: {$message}";
        }

        return count($failures).' object lock operations failed: '.implode(' | ', $lines);
    }
}
