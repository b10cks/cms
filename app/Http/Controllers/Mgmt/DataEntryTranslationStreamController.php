<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\Dto\StreamEvent;
use App\Services\Ai\Exceptions\AiServiceException;
use App\Services\Ai\Prompts\SystemPromptBuilder;
use App\Services\Ai\Prompts\UserPromptBuilder;
use App\Services\Ai\Support\AiSseStream;
use App\Services\Ai\Support\JsonExtractor;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataEntryTranslationStreamController extends Controller
{
    private const CHUNK_SIZE = 25;

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

        // Preconditions run before the stream opens, so a failure here is a plain
        // 422 JSON response rather than an error event mid-stream.
        $sourceLocale = $this->validatePreconditions($dataSource, $targetDimension);

        $aiConfig = $space->defaultAiConfig;

        if (! $aiConfig) {
            throw ValidationException::withMessages([
                'target_dimension' => ['No default AI configuration found for this space.'],
            ]);
        }

        $candidates = $this->candidates($dataSource, $sourceLocale, $targetDimension);

        return AiSseStream::response(
            fn () => $this->translate(
                $space,
                $dataSource,
                $targetDimension,
                $sourceLocale,
                $candidates,
                $aiConfig,
                $aiStreamService,
            ),
            ['endpoint' => 'data-entry-translation', 'space' => $space->id, 'data_source' => $dataSource->id],
        );
    }

    /**
     * Validate the data source can be translated and return the resolved source
     * locale.
     *
     * @throws ValidationException
     */
    private function validatePreconditions(DataSource $dataSource, string $targetDimension): string
    {
        $dimensionKeys = collect($dataSource->dimensions ?? [])->pluck('key')->filter()->values();

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

        return $sourceLocale;
    }

    /**
     * Entries with a non-empty source value but no value yet in the target
     * dimension — the ones worth translating.
     *
     * @return Collection<int, DataEntry>
     */
    private function candidates(DataSource $dataSource, string $sourceLocale, string $targetDimension): Collection
    {
        return DataEntry::query()
            ->where('data_source_id', $dataSource->id)
            ->get()
            ->filter(function (DataEntry $entry) use ($targetDimension, $sourceLocale): bool {
                $dimensions = is_array($entry->dimensions) ? $entry->dimensions : [];
                $sourceValue = trim((string) ($dimensions[$sourceLocale] ?? $entry->value ?? ''));
                $targetValue = trim((string) ($dimensions[$targetDimension] ?? ''));

                return $sourceValue !== '' && $targetValue === '';
            })
            ->values();
    }

    /**
     * Translate the candidates in chunks, emitting progress as it goes. A chunk
     * that yields no usable JSON aborts the run via {@see AiServiceException},
     * which the SSE transport surfaces as a coded error event.
     *
     * @param  Collection<int, DataEntry>  $candidates
     * @return Generator<StreamEvent>
     */
    private function translate(
        Space $space,
        DataSource $dataSource,
        string $targetDimension,
        string $sourceLocale,
        Collection $candidates,
        $aiConfig,
        AiStreamService $aiStreamService,
    ): Generator {
        $total = $candidates->count();
        $translated = 0;
        $skipped = 0;
        $processed = 0;
        $systemPrompt = (new SystemPromptBuilder($aiConfig))->forTranslation();

        yield StreamEvent::status('Preparing translations...');
        yield $this->progress('preparing', $processed, $translated, $skipped, $total, $targetDimension);

        if ($total === 0) {
            yield $this->summary($translated, $skipped, $processed, $total, $targetDimension, $sourceLocale);

            return;
        }

        $batches = (int) ceil($total / self::CHUNK_SIZE);

        foreach ($candidates->chunk(self::CHUNK_SIZE) as $index => $chunk) {
            yield StreamEvent::status(sprintf('Translating batch %d of %d...', $index + 1, $batches));

            $fields = [];
            foreach ($chunk as $entry) {
                $dimensions = is_array($entry->dimensions) ? $entry->dimensions : [];
                $fields[$entry->key] = trim((string) ($dimensions[$sourceLocale] ?? $entry->value ?? ''));
            }

            $result = $aiStreamService->generate(
                $space,
                $systemPrompt,
                UserPromptBuilder::translation($sourceLocale, $targetDimension, $fields),
                [],
                $aiConfig,
            );

            $decoded = JsonExtractor::decode($result, true);

            if (! is_array($decoded)) {
                throw AiServiceException::noResult();
            }

            DB::transaction(function () use ($chunk, $decoded, $targetDimension, &$translated, &$skipped, &$processed): void {
                foreach ($chunk as $entry) {
                    $processed++;
                    $value = $decoded[$entry->key] ?? null;

                    if (! is_string($value) || trim($value) === '') {
                        $skipped++;

                        continue;
                    }

                    $dimensions = is_array($entry->dimensions) ? $entry->dimensions : [];

                    if (trim((string) ($dimensions[$targetDimension] ?? '')) !== '') {
                        $skipped++;

                        continue;
                    }

                    $dimensions[$targetDimension] = $value;
                    $entry->dimensions = $dimensions;
                    $entry->save();
                    $translated++;
                }
            });

            Cache::forget("data_source:{$dataSource->id}:entries");

            yield $this->progress('processing', $processed, $translated, $skipped, $total, $targetDimension);
        }

        yield $this->summary($translated, $skipped, $processed, $total, $targetDimension, $sourceLocale);
    }

    private function progress(string $stage, int $processed, int $translated, int $skipped, int $total, string $targetDimension): StreamEvent
    {
        return StreamEvent::status(json_encode([
            'stage' => $stage,
            'processed' => $processed,
            'translated' => $translated,
            'skipped' => $skipped,
            'total' => $total,
            'target_dimension' => $targetDimension,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function summary(int $translated, int $skipped, int $processed, int $total, string $targetDimension, string $sourceLocale): StreamEvent
    {
        return StreamEvent::done('', [
            'translated_count' => $translated,
            'skipped_count' => $skipped,
            'processed_count' => $processed,
            'total_candidates' => $total,
            'target_dimension' => $targetDimension,
            'source_dimension' => 'default',
            'source_locale' => $sourceLocale,
        ]);
    }
}
