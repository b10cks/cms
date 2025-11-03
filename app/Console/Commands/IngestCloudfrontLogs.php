<?php

namespace App\Console\Commands;

use App\Services\Logging\CloudfrontLogAggregator;
use App\Services\Logging\CloudfrontLogParser;
use App\Services\Logging\S3LogFileManager;
use Aws\S3\S3Client;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class IngestCloudfrontLogs extends Command
{
    protected $signature = 'cloudfront:ingest-logs
                            {--bucket= : S3 bucket name}
                            {--prefix= : S3 prefix for log files}
                            {--limit=50 : Maximum number of files to process}
                            {--retry-failed : Reprocess files marked as failed}';

    protected $description = 'Ingest and process CloudFront logs from S3 using S3 tagging';

    private const array IGNORE_SPACE_IDS = [
        'spaces', 'users'
    ];

    private S3LogFileManager $fileManager;
    private CloudfrontLogParser $parser;
    private CloudfrontLogAggregator $aggregator;

    public function handle(): int
    {
        $this->info('Starting CloudFront log ingestion...');

        $bucket = $this->option('bucket') ?? config('services.cloudfront.log_bucket');
        $prefix = $this->option('prefix') ?? config('services.cloudfront.log_prefix', '');
        $limit = (int) $this->option('limit');

        if (!$bucket) {
            $this->error('S3 bucket not specified. Use --bucket option or set CLOUDFRONT_LOG_BUCKET in .env');
            return self::FAILURE;
        }

        $this->initializeServices();

        try {
            $logFiles = $this->fileManager->discoverUnprocessedLogs($bucket, $prefix, $limit);

            if ($logFiles->isEmpty()) {
                $this->info('No new log files to process.');
                return self::SUCCESS;
            }

            $this->info("Found {$logFiles->count()} unprocessed log files.");

            $progressBar = $this->output->createProgressBar($logFiles->count());
            $progressBar->start();

            foreach ($logFiles as $file) {
                try {
                    $this->processLogFile($bucket, $file);
                } catch (\Exception $e) {
                    $this->handleProcessingError($bucket, $file['key'], $e);
                }

                $progressBar->advance();
            }

            $this->aggregator->flush();

            $progressBar->finish();

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Fatal error during ingestion: {$e->getMessage()}");
            Log::error('CloudFront log ingestion failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }

    private function initializeServices(): void
    {
        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => config('services.aws.region', 'eu-west-1'),
            'credentials' => [
                'key' => config('services.aws.key'),
                'secret' => config('services.aws.secret'),
            ],
        ]);

        $this->fileManager = new S3LogFileManager($s3Client);
        $this->parser = new CloudfrontLogParser();
        $this->aggregator = new CloudfrontLogAggregator(
            config('services.cloudfront.ingestion.batch_size', 500)
        );
    }

    private function processLogFile(string $bucket, array $file): void
    {
        $key = $file['key'];

        $this->fileManager->markAsProcessing($bucket, $key);

        $content = $this->fileManager->getLogContent($bucket, $key);
        $lines = explode("\n", $content);

        $linesProcessed = 0;
        $linesSkipped = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            $parsed = $this->parser->parseLogLine($line);

            if (!$parsed) {
                $linesSkipped++;
                continue;
            }

            $this->processLogEntry($parsed);
            $linesProcessed++;
        }

        $this->fileManager->markAsProcessed($bucket, $key, [
            'lines_processed' => $linesProcessed,
            'lines_skipped' => $linesSkipped,
        ]);
    }

    private function processLogEntry(array $entry): void
    {
        $timestamp = Carbon::parse($entry['date'] . ' ' . $entry['time']);
        $uri = $entry['uri'];
        $queryString = $entry['query_string'];

        if ($this->parser->isApiRequest($uri)) {
            $spaceId = $this->parser->extractSpaceIdFromApiToken($queryString);

            if ($spaceId) {
                $this->aggregator->addApiHit(
                    $spaceId,
                    $timestamp,
                    $entry['status_code'],
                    $entry['ip_address'],
                    $entry['time_taken']
                );
            }
        } elseif ($this->parser->isTrafficRequest($uri)) {
            $spaceId = $this->parser->extractSpaceIdFromTraffic($uri);

            if ($spaceId && !in_array($spaceId, self::IGNORE_SPACE_IDS, true)) {
                $cacheHit = $this->parser->isCacheHit($entry['edge_result_type']);

                $this->aggregator->addTrafficUsage(
                    $spaceId,
                    $timestamp,
                    $entry['bytes_sent'],
                    $entry['bytes_received'],
                    $cacheHit
                );
            }
        }
    }

    private function handleProcessingError(string $bucket, string $key, \Exception $e): void
    {
        $error = substr($e->getMessage(), 0, 255);
        $this->fileManager->markAsFailed($bucket, $key, $error);

        Log::error("Failed to process CloudFront log: {$key}", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
