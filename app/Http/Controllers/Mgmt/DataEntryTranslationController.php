<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\Prompts\SystemPromptBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DataEntryTranslationController extends Controller
{
    public function __invoke(
        Request $request,
        Space $space,
        DataSource $dataSource,
        AiStreamService $aiStreamService,
    ): JsonResponse {
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

        if ($candidates->isEmpty()) {
            return response()->json([
                'data' => [
                    'translated_count' => 0,
                    'skipped_count' => 0,
                    'total_candidates' => 0,
                    'target_dimension' => $targetDimension,
                    'source_dimension' => 'default',
                    'source_locale' => $sourceLocale,
                ],
            ]);
        }

        $fields = [];

        foreach ($candidates as $entry) {
            $fields[$entry->id] = (string) ($entry->value ?? '');
        }

        $userPrompt =
            "Translate the following texts from {$sourceLocale} to {$targetDimension}.\n"
            ."Return only the translated flat JSON object.\n\n"
            .json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $promptBuilder = new SystemPromptBuilder($aiConfig);
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

        $translatedCount = 0;
        $skippedCount = 0;

        DB::transaction(function () use (
            $candidates,
            $decoded,
            $targetDimension,
            &$translatedCount,
            &$skippedCount
        ): void {
            foreach ($candidates as $entry) {
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

        return response()->json([
            'data' => [
                'translated_count' => $translatedCount,
                'skipped_count' => $skippedCount,
                'total_candidates' => $candidates->count(),
                'target_dimension' => $targetDimension,
                'source_dimension' => 'default',
                'source_locale' => $sourceLocale,
            ],
        ]);
    }
}
