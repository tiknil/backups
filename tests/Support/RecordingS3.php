<?php

namespace Tests\Support;

use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\RejectedPromise;
use Throwable;

/**
 * Handler for S3Client that records every command and answers through a
 * resolver, so tests assert against requests instead of a response queue.
 *
 * Resolver signature: fn (string $name, array $input, CommandInterface $command): array|Throwable
 */
final class RecordingS3
{
    /** @var array<int, array{name: string, input: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param  callable(string, array<string, mixed>, CommandInterface): (array<string, mixed>|Throwable)  $resolver
     */
    public function __construct(private readonly mixed $resolver) {}

    public function __invoke(CommandInterface $command, mixed $request): mixed
    {
        $input = $command->toArray();
        $this->calls[] = [
            'name' => $command->getName(),
            'input' => array_filter(
                $input,
                fn (string $key): bool => !str_starts_with($key, '@'),
                ARRAY_FILTER_USE_KEY,
            ),
        ];

        try {
            $result = ($this->resolver)($command->getName(), $input, $command);
        } catch (Throwable $e) {
            $result = $e;
        }

        if ($result instanceof Throwable) {
            return new RejectedPromise($result);
        }

        return Create::promiseFor(new Result([
            '@metadata' => ['statusCode' => 200, 'effectiveUri' => (string) $request->getUri()],
        ] + $result));
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_column($this->calls, 'name');
    }

    /** @return array<int, array<string, mixed>> */
    public function callsOf(string $name): array
    {
        return array_values(array_filter(
            $this->calls,
            fn (array $call): bool => $call['name'] === $name,
        ));
    }

    /** Inputs of the calls to one command, in order. */
    public function inputsOf(string $name): array
    {
        return array_column($this->callsOf($name), 'input');
    }
}
