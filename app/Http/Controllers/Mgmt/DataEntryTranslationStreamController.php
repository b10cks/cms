<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\Dto\StreamEvent;
use App\Services\Ai\Prompts\SystemPromptBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataEntryTranslationStreamController extends Controller
{
    public function __invoke(
        Request $request,
        Space $space,
        DataSource $dataSource,
        AiStreamService $aiStreamService,
    ): StreamedResponse {
        $this->authorize('bulkOperation', [DataEntry::class, $dataSource, $space]);

        $validated = $request->validate([
            'target_dimension' => ['required', 'string'],
        ]);

        $targetDimension = (string) $validated['target_dimension'];
        $availableDimensions = collect($dataSource->dimensions ?? []);
        $dimensionKeys = $availableDimensions->pluck('key')->filter()->values();

        if (! $dimensionKeys->contains($targetDimension)) {
            throw ValidationException::withMessages([
                'target_dimension' => ['The selected target dimension does not exist for this data source.'],
            ]);
        }

        if (! ($dataSource->settings['dimensions_translatable'] ?? false)) {
            throw ValidationException::withMessages([
                'target_dimension' => ['AI translation is only available for data sources with translatable dimensions enabled.'],
            ]);
        }

        $sourceLocale = (string) ($dataSource->settings['default_dimension_locale'] ?? '');

        if ($sourceLocale === '') {
            throw ValidationException::withMessages([
                'target_dimension' => ['The data source is missing a default dimension locale.'],
            ]);
        }

        if ($sourceLocale === $targetDimension) {
            throw ValidationException::withMessages([
                'target_dimension' => ['The target dimension must be different from the default dimension locale.'],
            ]);
        }

        $aiConfig = $space->defaultAiConfig;

        if (! $aiConfig) {
            throw ValidationException::withMessages([
                'target_dimension' => ['No default AI configuration found for this space.'],
            ]);
        }

        $entries = DataEntry::query()
            ->where('data_source_id', $dataSource->id)
            ->get();

        $candidates = $entries
            ->filter(function (DataEntry $entry) use ($targetDimension): bool {
                $dimensions = is_array($entry->dimensions) ? $entry->dimensions : [];
                $sourceValue = trim((string) ($entry->value ?? ''));
                $targetValue = trim((string) ($dimensions[$targetDimension] ?? ''));

                return $sourceValue !== '' && $targetValue === '';
            })
            ->values();

        return new StreamedResponse(
            function () use ($space, $dataSource, $targetDimension, $sourceLocale, $candidates, $aiConfig, $aiStreamService) {
                @set_time_limit(0);
                @ini_set('max_execution_time', '0');
                @ignore_user_abort(true);

                if (ob_get_level() === 0) {
                    ob_start();
                }

                $emit = static function (StreamEvent $event): void {
                    echo $event->toJsonLine()."\n\n";

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                };

                $ping = static function (): void {
                    echo ": ping\n\n";

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                };

                $totalCandidates = $candidates->count();
                $translatedCount = 0;
                $skippedCount = 0;
                $processedCount = 0;
                $chunkSize = 25;
                $promptBuilder = new SystemPromptBuilder($aiConfig);

                $ping();
                $emit(StreamEvent::status('Preparing translations...'));
                $emit(StreamEvent::status(json_encode([
                    'stage' => 'preparing',
                    'processed' => 0,
                    'translated' => 0,
                    'skipped' => 0,
                    'total' => $totalCandidates,
                    'target_dimension' => $targetDimension,
                ], JSON_UNESCAPED_UNICODE)));

                if ($totalCandidates === 0) {
                    $emit(StreamEvent::done('', [
                        'translated_count' => 0,
                        'skipped_count' => 0,
                        'processed_count' => 0,
                        'total_candidates' => 0,
                        'target_dimension' => $targetDimension,
                        'source_dimension' => 'default',
                        'source_locale' => $sourceLocale,
                    ]));

                    if (ob_get_level() > 0) {
                        ob_end_flush();
                    }

                    return;
                }

                try {
                    foreach ($candidates->chunk($chunkSize) as $chunkIndex => $chunk) {
                        $fields = [];

                        foreach ($chunk as $entry) {
                            $fields[$entry->id] = (string) ($entry->value ?? '');
                        }

                        $emit(StreamEvent::status(sprintf(
                            'Translating batch %d of %d...',
                            $chunkIndex + 1,
                            (int) ceil($totalCandidates / $chunkSize),
                        )));
                        $ping();

                        $userPrompt =
                            "Translate the following texts from {$sourceLocale} to {$targetDimension}.\n"
                            ."Return only the translated flat JSON object.\n\n"
                            .json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        $result = $aiStreamService->generate(
                            $space,
                            $promptBuilder->forTranslation(),
                            $userPrompt,
                            [],
                            $aiConfig,
                        );

                        if (! is_string($result) || trim($result) === '') {
                            throw ValidationException::withMessages([
                                'target_dimension' => ['The AI service did not return any translation output.'],
                            ]);
                        }

                        $decoded = json_decode($result, true);

                        if (! is_array($decoded)) {
                            throw ValidationException::withMessages([
                                'target_dimension' => ['The AI service returned an invalid translation payload.'],
                            ]);
                        }

                        DB::transaction(function () use (
                            $chunk,
                            $decoded,
                            $targetDimension,
                            &$translatedCount,
                            &$skippedCount,
                            &$processedCount
                        ): void {
                            foreach ($chunk as $entry) {
                                $processedCount++;
                                $translatedValue = $decoded[$entry->id] ?? null;

                                if (! is_string($translatedValue) || trim($translatedValue) === '') {
                                    $skippedCount++;

                                    continue;
                                }

                                $dimensions = is_array($entry->dimensions) ? $entry->dimensions : [];
                                $currentTargetValue = trim((string) ($dimensions[$targetDimension] ?? ''));

                                if ($currentTargetValue !== '') {
                                    $skippedCount++;

                                    continue;
                                }

                                $dimensions[$targetDimension] = $translatedValue;
                                $entry->dimensions = $dimensions;
                                $entry->save();
                                $translatedCount++;
                            }
                        });

                        Cache::forget("data_source:{$dataSource->id}:entries");

                        $emit(StreamEvent::status(json_encode([
                            'stage' => 'processing',
                            'processed' => $processedCount,
                            'translated' => $translatedCount,
                            'skipped' => $skippedCount,
                            'total' => $totalCandidates,
                            'target_dimension' => $targetDimension,
                        ], JSON_UNESCAPED_UNICODE)));
                        $ping();
                    }

                    $emit(StreamEvent::done('', [
                        'translated_count' => $translatedCount,
                        'skipped_count' => $skippedCount,
                        'processed_count' => $processedCount,
                        'total_candidates' => $totalCandidates,
                        'target_dimension' => $targetDimension,
                        'source_dimension' => 'default',
                        'source_locale' => $sourceLocale,
                    ]));
                } catch (\Throwable $e) {
                    $emit(StreamEvent::error($e->getMessage()));
                }

                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'close',
            ],
        );
    }
}
