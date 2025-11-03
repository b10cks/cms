<?php

namespace App\Services\Logging;

use Aws\S3\S3Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class S3LogFileManager
{
    private const TAG_PROCESSED = 'processed';
    private const TAG_PROCESSING = 'processing';
    private const TAG_FAILED = 'failed';

    private S3Client $s3Client;

    public function __construct(S3Client $s3Client)
    {
        $this->s3Client = $s3Client;
    }

    public function discoverUnprocessedLogs(
        string $bucket,
        string $prefix,
        int $limit
    ): Collection {
        $unprocessed = collect();
        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $bucket,
                'Prefix' => $prefix,
                'MaxKeys' => min($limit * 2, 1000),
            ];

            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }

            $result = $this->s3Client->listObjectsV2($params);

            foreach ($result['Contents'] ?? [] as $object) {
                $key = $object['Key'];

                if (!str_ends_with($key, '.gz')) {
                    continue;
                }

                if ($this->isProcessed($bucket, $key)) {
                    continue;
                }

                $unprocessed->push([
                    'key' => $key,
                    'size' => $object['Size'],
                    'last_modified' => $object['LastModified'],
                ]);

                if ($unprocessed->count() >= $limit) {
                    break 2;
                }
            }

            $continuationToken = $result['IsTruncated'] ? $result['NextContinuationToken'] : null;
        } while ($continuationToken && $unprocessed->count() < $limit);

        return $unprocessed;
    }

    public function isProcessed(string $bucket, string $key): bool
    {
        try {
            $result = $this->s3Client->getObjectTagging([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);

            $tags = collect($result['TagSet'] ?? []);

            return $tags->contains(function ($tag) {
                return $tag['Key'] === 'Status'
                    && in_array($tag['Value'], [self::TAG_PROCESSED, self::TAG_PROCESSING]);
            });
        } catch (\Exception $e) {
            Log::warning("Failed to get tags for {$key}: {$e->getMessage()}");

            return false;
        }
    }

    public function markAsProcessing(string $bucket, string $key): void
    {
        $this->addTag($bucket, $key, 'Status', self::TAG_PROCESSING);
    }

    public function markAsProcessed(string $bucket, string $key, array $metadata = []): void
    {
        $tags = [
            ['Key' => 'Status', 'Value' => self::TAG_PROCESSED],
            ['Key' => 'ProcessedAt', 'Value' => now()->toIso8601String()],
        ];

        if (isset($metadata['lines_processed'])) {
            $tags[] = ['Key' => 'LinesProcessed', 'Value' => (string)$metadata['lines_processed']];
        }

        if (isset($metadata['lines_skipped'])) {
            $tags[] = ['Key' => 'LinesSkipped', 'Value' => (string)$metadata['lines_skipped']];
        }

        $this->setTags($bucket, $key, $tags);
    }

    public function markAsFailed(string $bucket, string $key, string $error): void
    {
        $tags = [
            ['Key' => 'Status', 'Value' => self::TAG_FAILED],
            ['Key' => 'FailedAt', 'Value' => now()->toIso8601String()],
            ['Key' => 'Error', 'Value' => substr($error, 0, 255)],
        ];

        $this->setTags($bucket, $key, $tags);
    }

    public function getLogContent(string $bucket, string $key): string
    {
        $result = $this->s3Client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        $compressedContent = $result['Body']->getContents();
        $content = gzdecode($compressedContent);

        if ($content === false) {
            throw new \RuntimeException("Failed to decompress log file: {$key}");
        }

        return $content;
    }

    public function findProcessedLogs(
        string $bucket,
        string $prefix,
        int $olderThanDays
    ): Collection {
        $cutoffDate = now()->subDays($olderThanDays);
        $processedLogs = collect();
        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $bucket,
                'Prefix' => $prefix,
                'MaxKeys' => 1000,
            ];

            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }

            $result = $this->s3Client->listObjectsV2($params);

            foreach ($result['Contents'] ?? [] as $object) {
                $key = $object['Key'];

                if (!str_ends_with($key, '.gz')) {
                    continue;
                }

                try {
                    $tagResult = $this->s3Client->getObjectTagging([
                        'Bucket' => $bucket,
                        'Key' => $key,
                    ]);

                    $tags = collect($tagResult['TagSet'] ?? []);

                    $statusTag = $tags->firstWhere('Key', 'Status');
                    $processedAtTag = $tags->firstWhere('Key', 'ProcessedAt');

                    if ($statusTag && $statusTag['Value'] === self::TAG_PROCESSED && $processedAtTag) {
                        $processedAt = \Carbon\Carbon::parse($processedAtTag['Value']);

                        if ($processedAt->lt($cutoffDate)) {
                            $processedLogs->push([
                                'key' => $key,
                                'size' => $object['Size'],
                                'processed_at' => $processedAt,
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to check tags for {$key}: {$e->getMessage()}");
                    continue;
                }
            }

            $continuationToken = $result['IsTruncated'] ? $result['NextContinuationToken'] : null;
        } while ($continuationToken);

        return $processedLogs;
    }

    public function deleteLogFiles(string $bucket, array $keys): int
    {
        if (empty($keys)) {
            return 0;
        }

        $deleted = 0;
        $chunks = array_chunk($keys, 1000);

        foreach ($chunks as $chunk) {
            $objects = array_map(fn($key) => ['Key' => $key], $chunk);

            try {
                $result = $this->s3Client->deleteObjects([
                    'Bucket' => $bucket,
                    'Delete' => [
                        'Objects' => $objects,
                        'Quiet' => false,
                    ],
                ]);

                $deleted += count($result['Deleted'] ?? []);

                if (!empty($result['Errors'])) {
                    foreach ($result['Errors'] as $error) {
                        Log::error("Failed to delete {$error['Key']}: {$error['Message']}");
                    }
                }
            } catch (\Exception $e) {
                Log::error("Batch delete failed: {$e->getMessage()}");
            }
        }

        return $deleted;
    }

    private function addTag(string $bucket, string $key, string $tagKey, string $tagValue): void
    {
        $existingTags = [];

        try {
            $result = $this->s3Client->getObjectTagging([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);

            $existingTags = $result['TagSet'] ?? [];
        } catch (\Exception $e) {
            Log::warning("Failed to get existing tags for {$key}: {$e->getMessage()}");
        }

        $tags = collect($existingTags)
            ->reject(fn($tag) => $tag['Key'] === $tagKey)
            ->push(['Key' => $tagKey, 'Value' => $tagValue])
            ->values()
            ->toArray();

        $this->setTags($bucket, $key, $tags);
    }

    private function setTags(string $bucket, string $key, array $tags): void
    {
        try {
            $this->s3Client->putObjectTagging([
                'Bucket' => $bucket,
                'Key' => $key,
                'Tagging' => [
                    'TagSet' => $tags,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to set tags for {$key}: {$e->getMessage()}");
        }
    }
}
