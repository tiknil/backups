<?php

namespace Tiknil\Backups\Core;

use Aws\S3\S3Client;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Carbon;

/**
 * Thin wrapper around an S3 disk configuration.
 *
 * Convention: every method that takes a "key" expects an absolute object key
 * (root included); build it with {@see fullPath()}. Listing helpers are the
 * exception: they take path segments relative to the root and return absolute
 * keys.
 */
class S3Disk
{
    public const RETENTION_COMPLIANCE = 'COMPLIANCE';

    public const RETENTION_GOVERNANCE = 'GOVERNANCE';

    /**
     * @param  array<string, mixed>  $config  Raw Laravel filesystem disk config.
     * @param  string|null  $name  Filesystem disk name, when known.
     */
    public function __construct(
        public S3Client $client,
        public string $bucket,
        public string $root = '',
        public array $config = [],
        public ?string $name = null,
    ) {}

    public static function fromConfig(array $diskConfig, ?string $name = null): self
    {
        if (($diskConfig['driver'] ?? null) !== 's3') {
            throw new \RuntimeException('Disk must be an S3 disk.');
        }

        $options = [
            'version' => 'latest',
            'region' => $diskConfig['region'],
        ];

        if (!empty($diskConfig['key']) && !empty($diskConfig['secret'])) {
            $options['credentials'] = [
                'key' => $diskConfig['key'],
                'secret' => $diskConfig['secret'],
            ];
        }

        if (!empty($diskConfig['token'])) {
            $options['credentials']['token'] = $diskConfig['token'];
        }

        if (!empty($diskConfig['endpoint'])) {
            $options['endpoint'] = $diskConfig['endpoint'];
        }

        if (array_key_exists('use_path_style_endpoint', $diskConfig)) {
            $options['use_path_style_endpoint'] = (bool) $diskConfig['use_path_style_endpoint'];
        }

        return new self(
            new S3Client($options),
            $diskConfig['bucket'],
            trim($diskConfig['root'] ?? '', '/'),
            $diskConfig,
            $name,
        );
    }

    public static function fromDiskName(string $name): self
    {
        $config = config("filesystems.disks.{$name}");

        if (!is_array($config)) {
            throw new \RuntimeException("Disk '{$name}' is not configured.");
        }

        return self::fromConfig($config, $name);
    }

    public function ping(): void
    {
        $this->client->listObjectsV2([
            'Bucket' => $this->bucket,
            'MaxKeys' => 1,
        ]);
    }

    /** Build a normalized object key, prefixed with the disk root. */
    public function fullPath(string ...$segments): string
    {
        $segments = array_map(fn (string $segment): string => trim($segment, '/'), $segments);

        return implode('/', array_filter(
            array_merge([$this->root], $segments),
            fn (string $segment): bool => $segment !== '',
        ));
    }

    /** Build a listing prefix (trailing slash) from path segments. */
    public function prefix(string ...$segments): string
    {
        $path = $this->fullPath(...$segments);

        return $path === '' ? '' : $path.'/';
    }

    /** First object key under a prefix, or null when the prefix is empty. */
    public function firstKey(string ...$segments): ?string
    {
        $result = $this->client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $this->prefix(...$segments),
            'MaxKeys' => 1,
        ]);

        return $result['Contents'][0]['Key'] ?? null;
    }

    public function isEmpty(string ...$segments): bool
    {
        return $this->firstKey(...$segments) === null;
    }

    public function exists(string $key): bool
    {
        return $this->client->doesObjectExist($this->bucket, $key);
    }

    /** @return array<int, string> */
    public function keys(string ...$segments): array
    {
        $keys = [];

        $paginator = $this->client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->bucket,
            'Prefix' => $this->prefix(...$segments),
        ]);

        foreach ($paginator as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $keys[] = $object['Key'];
            }
        }

        return $keys;
    }

    public function copy(string $fromKey, string $toKey): void
    {
        $this->client->copyObject([
            'Bucket' => $this->bucket,
            'Key' => $toKey,
            'CopySource' => $this->bucket.'/'.$fromKey,
        ]);
    }

    public function legalHold(string $key, bool $enabled): void
    {
        $this->legalHoldAsync($key, $enabled)->wait();
    }

    public function legalHoldAsync(string $key, bool $enabled): PromiseInterface
    {
        return $this->client->putObjectLegalHoldAsync([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'LegalHold' => ['Status' => $enabled ? 'ON' : 'OFF'],
        ]);
    }

    /** Apply retention without shortening an existing period. */
    public function retainFor(string $key, int $days): void
    {
        $this->retainForAsync($key, $days)->wait();
    }

    /** @see retainFor() */
    public function retainForAsync(string $key, int $days): PromiseInterface
    {
        $until = now('UTC')->addDays($days);

        return $this->client->headObjectAsync([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->then(function ($head) use ($key, $until): ?PromiseInterface {
            $existingUntil = $head['ObjectLockRetainUntilDate'] ?? null;

            if ($existingUntil !== null && Carbon::parse($existingUntil)->greaterThanOrEqualTo($until)) {
                return null;
            }

            return $this->client->putObjectRetentionAsync([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Retention' => [
                    'Mode' => self::RETENTION_COMPLIANCE,
                    'RetainUntilDate' => $until->format('Y-m-d\\TH:i:s\\Z'),
                ],
            ]);
        });
    }

    /** Read one object tag, returning null when it is absent. */
    public function tag(string $key, string $tag): ?string
    {
        return $this->tagAsync($key, $tag)->wait();
    }

    /** @see tag() */
    public function tagAsync(string $key, string $tag): PromiseInterface
    {
        return $this->client->getObjectTaggingAsync([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->then(function ($result) use ($tag): ?string {
            foreach ($result['TagSet'] ?? [] as $item) {
                if ($item['Key'] === $tag) {
                    return $item['Value'];
                }
            }

            return null;
        });
    }

    /** Add, update, or remove a tag while preserving all other tags. */
    public function setTag(string $key, string $tag, ?string $value): void
    {
        $this->setTagAsync($key, $tag, $value)->wait();
    }

    /** @see setTag() */
    public function setTagAsync(string $key, string $tag, ?string $value): PromiseInterface
    {
        return $this->client->getObjectTaggingAsync([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->then(function ($result) use ($key, $tag, $value): PromiseInterface {
            $tags = [];

            foreach ($result['TagSet'] ?? [] as $item) {
                if ($item['Key'] !== $tag) {
                    $tags[] = $item;
                }
            }

            if ($value !== null) {
                $tags[] = ['Key' => $tag, 'Value' => $value];
            }

            return $this->client->putObjectTaggingAsync([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Tagging' => ['TagSet' => $tags],
            ]);
        });
    }

    public function applyRetention(
        string $key,
        RetentionPeriod $period,
    ): void {
        $until = now('UTC')->addDays($period->retentionDays())->endOfDay()->format('Y-m-d\TH:i:s\Z');
        $this->client->putObjectRetention([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Retention' => [
                'Mode' => self::RETENTION_COMPLIANCE,
                'RetainUntilDate' => $until,
            ],
        ]);

        $this->client->putObjectTagging([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Tagging' => [
                'TagSet' => [
                    ['Key' => 'backup-class', 'Value' => $period->name],
                ],
            ],
        ]);
    }
}
