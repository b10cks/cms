<?php

namespace App\Console\Commands;

use App\Services\Documentation\OpenApiGenerator;
use Illuminate\Console\Command;

class GenerateApiDocs extends Command
{
    protected $signature = 'docs:generate
                            {--prefix= : Generate docs for specific prefix (e.g., api/v1)}
                            {--output= : Output directory (default: public/docs)}
                            {--format=json : Output format (json or yaml)}';

    protected $description = 'Generate OpenAPI documentation for configured API routes';

    public function handle(OpenApiGenerator $generator): int
    {
        $this->info('🚀 Generating API documentation...');

        try {
            $prefix = $this->option('prefix');
            $outputDir = $this->option('output');
            $format = $this->option('format');

            // Validate format
            if (!in_array($format, ['json', 'yaml'])) {
                $this->error("Invalid format '{$format}'. Use 'json' or 'yaml'.");
                return self::FAILURE;
            }

            $startTime = microtime(true);

            if ($prefix) {
                // Generate for specific prefix
                $this->info("📦 Generating documentation for prefix: {$prefix}");
                $spec = $generator->generateForPrefix($prefix);

                if (empty($spec)) {
                    $this->error("No routes found for prefix: {$prefix}");
                    return self::FAILURE;
                }

                // Write to file
                if ($outputDir) {
                    $directory = $outputDir;
                } else {
                    $directory = config('docs.output.directory', public_path('docs'));
                }

                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                $filename = str_replace('/', '_', $prefix) . '.json';
                $filepath = $directory . '/' . $filename;

                if ($format === 'json') {
                    $content = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } else {
                    // YAML format
                    $content = $this->arrayToYaml($spec);
                }

                file_put_contents($filepath, $content);

                $elapsedTime = round((microtime(true) - $startTime) * 1000, 2);
                $fileSize = filesize($filepath);

                $this->info("✅ Documentation generated successfully!");
                $this->line("📁 File: <comment>{$filepath}</comment>");
                $this->line("📊 Size: <comment>" . $this->formatBytes($fileSize) . "</comment>");
                $this->line("⏱️  Time: <comment>{$elapsedTime}ms</comment>");

                return self::SUCCESS;
            } else {
                // Generate for all prefixes
                $this->info('📦 Generating documentation for all configured prefixes...');

                $files = $generator->writeToFiles();

                $elapsedTime = round((microtime(true) - $startTime) * 1000, 2);

                $this->info("✅ Documentation generated successfully!");
                $this->newLine();

                $this->table(
                    ['Prefix', 'File Path', 'Size'],
                    array_map(function ($prefix, $filepath) {
                        $size = filesize($filepath);
                        return [
                            $prefix,
                            $filepath,
                            $this->formatBytes($size),
                        ];
                    }, array_keys($files), $files)
                );

                $this->newLine();
                $this->line("⏱️  Total time: <comment>{$elapsedTime}ms</comment>");
                $this->line("💡 View documentation at: <comment>" . config('app.url') . "/docs/index.html</comment>");

                return self::SUCCESS;
            }
        } catch (\Exception $e) {
            $this->error("❌ Failed to generate documentation");
            $this->error("Error: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Format bytes to human-readable format
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Convert array to YAML (simple implementation)
     */
    protected function arrayToYaml(array $array, int $depth = 0): string
    {
        $yaml = '';
        $indent = str_repeat(' ', $depth * 2);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $yaml .= "{$indent}{$key}:\n";
                $yaml .= $this->arrayToYaml($value, $depth + 1);
            } else {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_string($value) && (str_contains($value, ':') || str_contains($value, '#'))) {
                    $value = "'{$value}'";
                }
                $yaml .= "{$indent}{$key}: {$value}\n";
            }
        }

        return $yaml;
    }
}
