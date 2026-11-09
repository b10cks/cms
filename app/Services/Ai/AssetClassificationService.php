<?php

namespace App\Services\Ai;

use App\Enums\AiRunState;
use App\Jobs\Space\ClassifyAssetJob;
use App\Models\Management\AssetClassificationRun;
use App\Models\Management\Space;
use App\Models\Management\SpaceAiConfig;
use App\Models\Space\AiRun;
use App\Models\Space\Asset;
use App\Models\Space\AssetTag;
use App\Models\User;
use App\Services\Ai\Assets\AssetFieldRules;
use App\Services\Ai\Assets\AssetStructure;
use App\Services\Ai\Assets\AssetVision;
use App\Services\Ai\Assets\ClassificationReport;
use App\Services\Ai\Exceptions\AiServiceException;
use App\Services\Ai\Prompts\SystemPromptBuilder;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Skills\SkillResolver;
use App\Services\Ai\Support\JsonExtractor;
use App\Services\Asset\AssetMetadataFieldResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fills asset metadata fields (title, alt, description, ...) and optionally
 * tags from what a vision model sees in the image. By default only empty
 * slots are written so editors keep the last word; `overwrite` regenerates
 * every allowed field on request.
 *
 * Queueing is the only entry point for callers (upload hook, mass action);
 * {@see classify()} runs inside {@see ClassifyAssetJob}. Valid output is saved
 * even when other requested values are not usable; {@see ClassificationReport}
 * accounts for each of them on the agent run and on the asset's provenance.
 */
class AssetClassificationService
{
    public const REASON_MODEL_NOT_VISION = 'model_not_vision';

    /** Longest edge sent to the model; anything larger is downscaled. */
    private const MAX_EDGE = 1024;

    private const HIGH_DETAIL_EDGE = 2048;

    /** Taxonomy size the prompt carries; larger spaces get the tags matching the asset's hints, then the most used. */
    private const MAX_TAGS_IN_PROMPT = 200;

    private const MAX_RELEVANCE_TERMS = 40;

    /** Open assets a finished classification run lists; the counts stay complete. */
    private const MAX_REPORTED_ASSETS = 100;

    private const MAX_TAGS_PER_ASSET = 5;

    public function __construct(
        private readonly AssetMetadataFieldResolver $fieldResolver,
        private readonly AssetVision $vision,
        private readonly AssetFieldRules $rules,
        private readonly AiRunService $runs,
        private readonly SkillResolver $skills,
        private readonly ModelRegistry $registry,
    ) {}

    public function isEnabled(Space $space): bool
    {
        // Settings fall back to `false` for unknown paths, so only an explicit
        // opt-out counts as disabled.
        return data_get($space->settings->toArray(), 'ai.enabled') !== false;
    }

    public function autoClassifiesUploads(Space $space): bool
    {
        return $this->isEnabled($space)
            && (bool) $space->settings->{'ai.asset_classification.auto_on_upload'};
    }

    public function suggestsTags(Space $space): bool
    {
        return (bool) $space->settings->{'ai.asset_classification.suggest_tags'};
    }

    public function isClassifiable(?string $mimeType): bool
    {
        return \is_string($mimeType) && str_starts_with($mimeType, 'image/');
    }

    /**
     * Language keys asset data is stored under: `_default` plus every
     * configured language code.
     *
     * @return array<int, string>
     */
    public function availableLanguages(Space $space): array
    {
        return array_keys($this->languageNames($space));
    }

    /**
     * @param  array<int, string>  $languages
     */
    public function validateLanguages(Space $space, array $languages): bool
    {
        $allowed = $this->availableLanguages($space);

        return $languages !== []
            && array_diff($languages, $allowed) === [];
    }

    /**
     * The AI config classification will run with, verified to point at a
     * vision-capable model.
     *
     * @throws AiServiceException
     */
    public function resolveVisionConfig(Space $space, ?string $configId = null): SpaceAiConfig
    {
        $config = $configId
            ? $space->aiConfigs()->find($configId)
            : $space->defaultAiConfig;

        if (! $config) {
            throw AiServiceException::notConfigured();
        }

        $model = $this->registry->findModelForConfig($config->driver, $config->model);

        if (! $model?->supportsVision) {
            throw new AiServiceException(
                self::REASON_MODEL_NOT_VISION,
                'The AI configuration "'.$config->name.'" uses a model without image support. Pick a vision-capable model to classify assets.',
            );
        }

        return $config;
    }

    /**
     * Queue one job per image asset in the query that has something to fill.
     * Returns how many were queued and how many needed nothing.
     *
     * @param  Builder<Asset>  $query
     * @param  array<int, string>|null  $languages  null = every space language
     * @return array{run_id: string, queued: int, skipped: int}
     */
    public function queue(
        Space $space,
        User $actor,
        Builder $query,
        ?array $languages = null,
        ?string $configId = null,
        bool $overwrite = false,
        ?string $altContext = null,
        bool $decorative = false,
        bool $highDetail = false,
    ): array {
        $languages ??= $this->availableLanguages($space);
        $queued = 0;
        $skipped = 0;
        $run = AssetClassificationRun::query()->create(['space_id' => $space->id]);

        try {
            $query
                ->where('mime_type', 'like', 'image/%')
                ->orderBy('id')
                ->chunkById(200, function ($assets) use ($space, $actor, $languages, $configId, $overwrite, $altContext, $decorative, $highDetail, $run, &$queued, &$skipped): void {
                    foreach ($assets as $asset) {
                        if (! $this->needsClassification($space, $asset, $languages, $overwrite, $decorative, $altContext)) {
                            $skipped++;

                            continue;
                        }

                        // Count before dispatch: a sync worker can finish immediately.
                        $run->increment('queued');
                        ClassifyAssetJob::dispatch($space, $actor->id, $asset->id, $languages, $configId, $overwrite, $run->id, $altContext, $decorative, $highDetail);
                        $queued++;
                    }
                });
        } finally {
            $run->update(['enqueuing' => false]);
        }

        return ['run_id' => $run->id, 'queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Queue a single freshly stored asset when the space auto-classifies
     * uploads. Silent when it does not apply.
     */
    public function queueUpload(Space $space, User $actor, Asset $asset): void
    {
        if (! $this->autoClassifiesUploads($space) || ! $this->isClassifiable($asset->mime_type)) {
            return;
        }

        $languages = $this->availableLanguages($space);

        if (! $this->needsClassification($space, $asset, $languages)) {
            return;
        }

        ClassifyAssetJob::dispatch($space, $actor->id, $asset->id, $languages);
    }

    /**
     * Run the classification for one asset and persist the result.
     *
     * @param  array<int, string>  $languages
     * @param  string|null  $classificationRunId  the mass run this belongs to, stored with the report
     * @return 'updated'|'skipped' `updated` also covers partial output; the report tells what is open
     *
     * @throws AiServiceException when the space cannot use AI right now
     * @throws \RuntimeException when the image cannot be read or converted
     */
    public function classify(
        Space $space,
        User $actor,
        Asset $asset,
        array $languages,
        SpaceAiConfig $config,
        bool $overwrite = false,
        ?string $altContext = null,
        bool $decorative = false,
        bool $highDetail = false,
        ?string $classificationRunId = null,
    ): string {
        if (! $this->isClassifiable($asset->mime_type)) {
            return 'skipped';
        }

        $fieldKeys = $this->allowedFieldKeys($space, $asset);
        $targetKeys = $overwrite
            ? $this->allTargetKeys($fieldKeys, $languages)
            : $this->emptyTargetKeys($space, $asset, $languages, $fieldKeys);
        $forceAlt = $decorative || ($altContext !== null && trim($altContext) !== '');

        if ($forceAlt) {
            foreach ($this->allTargetKeys(array_intersect($fieldKeys, ['alt', 'alt_text']), $languages) as $key) {
                $targetKeys[] = $key;
            }
            $targetKeys = array_values(array_unique($targetKeys));
        }
        $taxonomy = $this->suggestsTags($space) ? $this->tagTaxonomy($asset, $altContext) : ['tags' => [], 'total' => 0];
        $tagsByName = $taxonomy['tags'];
        $taxonomyReport = ['shown' => \count($tagsByName), 'total' => $taxonomy['total'], 'truncated' => \count($tagsByName) < $taxonomy['total']];

        if ($targetKeys === [] && ($tagsByName === [] || ! $this->wantsTags($asset, $overwrite))) {
            return 'skipped';
        }

        $run = $this->runs->runToCompletion($this->startRun(
            $space,
            $actor,
            $asset,
            $this->imagePart($asset, $highDetail),
            $languages,
            $fieldKeys,
            $targetKeys,
            array_keys($tagsByName),
            $config,
            $altContext,
            $decorative,
            $taxonomy['total'],
        ));

        $raw = $run->state === AiRunState::Completed ? ($run->result['text'] ?? null) : null;

        if ($raw === null || ! \is_array(JsonExtractor::decode($raw))) {
            throw AiServiceException::noResult();
        }

        if ($taxonomyReport['truncated']) {
            $tagsByName += $this->tagsNamedIn($raw, $tagsByName);
        }

        $result = $this->parseOutput($raw, $targetKeys, $tagsByName, $decorative);
        $report = fn (array $written, array $writtenTags) => ClassificationReport::build(
            $languages,
            $targetKeys,
            $result,
            $written,
            $writtenTags,
            $decorative,
            $tagsByName !== [],
            $taxonomyReport,
        );

        if ($result['fields'] === [] && $result['tags'] === []) {
            Log::info('Asset classification produced no usable values', [
                'space_id' => $space->id,
                'asset_id' => $asset->id,
            ]);
            $this->storeReport($run, $asset, $classificationRunId, $report([], []));

            return 'skipped';
        }

        // Re-read so an edit saved while the model was thinking wins over the
        // generated text unless the editor explicitly asked to overwrite.
        $asset->refresh();
        $currentData = \is_array($asset->data) ? $asset->data : [];
        $mergedData = $this->mergeFields(
            $currentData,
            $result['fields'],
            $overwrite,
            $forceAlt,
        );
        $written = [];

        foreach ($result['fields'] as $language => $values) {
            foreach ($values as $field => $value) {
                $before = $currentData['fields'][$language][$field] ?? null;
                $after = $mergedData['fields'][$language][$field] ?? null;

                if ($before !== $value && $after === $value) {
                    $written[] = "{$language}.{$field}";
                }
            }
        }

        $writtenTags = array_values(array_diff($result['tags'], $asset->tags ?? []));
        $finished = $report($written, $writtenTags);

        $asset->data = $mergedData;

        if ($result['tags'] !== []) {
            $asset->tags = array_values(array_unique([...($asset->tags ?? []), ...$result['tags']]));
        }

        if (! $asset->isDirty(['data', 'tags'])) {
            $this->storeReport($run, $asset, $classificationRunId, $finished);

            return 'skipped';
        }

        $asset->metadata = $this->withProvenance(
            \is_array($asset->metadata) ? $asset->metadata : [],
            $config,
            $written,
            $result['tags'],
            $finished,
        );
        $asset->save();
        $this->storeReport($run, $asset, $classificationRunId, $finished);

        return 'updated';
    }

    /**
     * Decode the model's flat `{"lang.field": "value", "tags": [...]}` object,
     * keeping only requested field keys and tag names from the taxonomy.
     *
     * @param  array<int, string>  $targetKeys
     * @param  array<string, string>  $tagsByName  lower-cased tag name => tag id
     * @return array{fields: array<string, array<string, string>>, tags: array<int, string>}
     */
    public function parseResponse(string $content, array $targetKeys, array $tagsByName = [], bool $decorative = false): array
    {
        $parsed = $this->parseOutput($content, $targetKeys, $tagsByName, $decorative);

        return ['fields' => $parsed['fields'], 'tags' => $parsed['tags']];
    }

    /**
     * {@see parseResponse()} that also says what happened to the rest: values
     * refused with a reason, values the model left empty because the image does
     * not settle them, tag names that are no tags, and a `tags` value that is
     * absent or no list.
     *
     * @param  array<int, string>  $targetKeys
     * @param  array<string, string>  $tagsByName  lower-cased tag name => tag id
     * @return array{fields: array<string, array<string, string>>, tags: array<int, string>, rejected: array<string, string>, uninferable: list<string>, unknown_tags: list<string>, tags_malformed: bool}
     */
    private function parseOutput(string $content, array $targetKeys, array $tagsByName, bool $decorative): array
    {
        $decoded = JsonExtractor::decode($content);

        if (! \is_array($decoded)) {
            return ['fields' => [], 'tags' => [], 'rejected' => [], 'uninferable' => [], 'unknown_tags' => [], 'tags_malformed' => $tagsByName !== []];
        }

        $allowed = array_flip($targetKeys);
        $fields = [];
        $rejected = [];
        $uninferable = [];

        foreach ($decoded as $flatKey => $value) {
            if (! \is_string($flatKey) || ! isset($allowed[$flatKey])) {
                continue;
            }

            if (! \is_string($value)) {
                $rejected[$flatKey] = 'must be text';

                continue;
            }

            $text = trim((string) $value);

            [$language, $field] = explode('.', $flatKey, 2);

            if ($decorative && \in_array($field, ['alt', 'alt_text'], true)) {
                $fields[$language][$field] = '';

                continue;
            }

            if ($text === '') {
                $uninferable[] = $flatKey;

                continue;
            }

            $problem = $this->rules->textProblem($field, $text);
            if ($problem !== null) {
                $rejected[$flatKey] = $problem;

                continue;
            }

            $fields[$language][$field] = $text;
        }

        if ($decorative) {
            foreach ($targetKeys as $flatKey) {
                [$language, $field] = explode('.', $flatKey, 2);

                if (\in_array($field, ['alt', 'alt_text'], true)) {
                    $fields[$language][$field] = '';
                    unset($rejected[$flatKey]);
                }
            }
        }

        $tags = [];
        $unknownTags = [];

        if ($tagsByName !== [] && \is_array($decoded['tags'] ?? null)) {
            foreach ($decoded['tags'] as $name) {
                $id = \is_string($name) ? ($tagsByName[mb_strtolower(trim($name))] ?? null) : null;

                if ($id !== null && ! \in_array($id, $tags, true)) {
                    $tags[] = $id;
                } elseif ($id === null && \is_string($name) && \count($unknownTags) < self::MAX_TAGS_PER_ASSET) {
                    $unknownTags[] = mb_substr(trim($name), 0, 100);
                }

                if (\count($tags) >= self::MAX_TAGS_PER_ASSET) {
                    break;
                }
            }
        }

        return [
            'fields' => $fields,
            'tags' => $tags,
            'rejected' => $rejected,
            'uninferable' => $uninferable,
            'unknown_tags' => $unknownTags,
            'tags_malformed' => $tagsByName !== [] && ! \is_array($decoded['tags'] ?? null),
        ];
    }

    /** The locale a language key of asset data is written in. */
    private function localeOf(Space $space, string $language): string
    {
        return $language === AssetMetadataFieldResolver::DEFAULT_LANGUAGE_KEY ? $space->settings->getDefaultLanguage() : $language;
    }

    /**
     * How a finished mass run went, field by field, from the reports of its
     * agent runs in the space database: counts per status and the assets with
     * open values. The caller enters the space. Counted once the run finished,
     * since the reports are not indexed and clients poll while it runs.
     *
     * @return array{complete: int, partial: int, none: int, assets: list<array<string, mixed>>, truncated: bool}
     */
    public function runReport(AssetClassificationRun $run): array
    {
        $reports = fn () => AiRun::query()
            ->where('surface', 'asset')
            ->where('created_at', '>=', $run->created_at)
            ->where('result->classification->run_id', $run->id);
        $count = fn (string $status) => $reports()->where('result->classification->status', $status)->count();
        $partial = $count(ClassificationReport::PARTIAL);
        $none = $count(ClassificationReport::NONE);

        return [
            'complete' => $count(ClassificationReport::COMPLETE),
            'partial' => $partial,
            'none' => $none,
            'assets' => $reports()
                ->whereIn('result->classification->status', [ClassificationReport::PARTIAL, ClassificationReport::NONE])
                ->orderBy('id')
                ->limit(self::MAX_REPORTED_ASSETS)
                ->get(['id', 'result'])
                ->map(fn (AiRun $agentRun) => array_diff_key($agentRun->result['classification'], ['run_id' => true]))
                ->all(),
            'truncated' => $partial + $none > self::MAX_REPORTED_ASSETS,
        ];
    }

    /** @param  array<string, mixed>  $report */
    private function storeReport(AiRun $run, Asset $asset, ?string $classificationRunId, array $report): void
    {
        $run->result = [...($run->result ?? []), 'classification' => ['run_id' => $classificationRunId, 'asset_id' => $asset->id, ...$report]];
        $run->save();
    }

    /**
     * Merge generated values into asset data. Without `overwrite` only empty
     * slots are written.
     *
     * @param  array<string, mixed>  $currentData
     * @param  array<string, array<string, string>>  $classification
     * @return array<string, mixed>
     */
    public function mergeFields(array $currentData, array $classification, bool $overwrite = false, bool $forceAlt = false): array
    {
        $fields = \is_array($currentData['fields'] ?? null) ? $currentData['fields'] : [];

        foreach ($classification as $language => $values) {
            $languageValues = \is_array($fields[$language] ?? null) ? $fields[$language] : [];

            foreach ($values as $fieldKey => $value) {
                if ($overwrite || ($forceAlt && \in_array($fieldKey, ['alt', 'alt_text'], true)) || $this->isEmptyValue($languageValues[$fieldKey] ?? null)) {
                    $languageValues[$fieldKey] = $value;
                }
            }

            $fields[$language] = $languageValues;
        }

        $currentData['fields'] = $fields;

        return $currentData;
    }

    /**
     * Whether a job for this asset would have anything to do.
     *
     * @param  array<int, string>  $languages
     */
    public function needsClassification(Space $space, Asset $asset, array $languages, bool $overwrite = false, bool $decorative = false, ?string $altContext = null): bool
    {
        if (($decorative || ($altContext !== null && trim($altContext) !== ''))
            && array_intersect($this->allowedFieldKeys($space, $asset), ['alt', 'alt_text']) !== []) {
            return true;
        }

        if ($overwrite) {
            return $this->allowedFieldKeys($space, $asset) !== [] || $this->suggestsTags($space);
        }

        return $this->emptyTargetKeys($space, $asset, $languages) !== []
            || ($this->suggestsTags($space) && $this->wantsTags($asset, false));
    }

    /**
     * `language.field` keys that are allowed for the asset and empty.
     *
     * @param  array<int, string>  $languages
     * @param  array<int, string>|null  $fieldKeys  resolved when omitted
     * @return array<int, string>
     */
    public function emptyTargetKeys(Space $space, Asset $asset, array $languages, ?array $fieldKeys = null): array
    {
        $fieldKeys ??= $this->allowedFieldKeys($space, $asset);
        $data = \is_array($asset->data) ? $asset->data : [];
        $fields = \is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $targetKeys = [];

        foreach ($languages as $language) {
            $languageValues = \is_array($fields[$language] ?? null) ? $fields[$language] : [];

            foreach ($fieldKeys as $fieldKey) {
                if ($this->isEmptyValue($languageValues[$fieldKey] ?? null)) {
                    $targetKeys[] = "{$language}.{$fieldKey}";
                }
            }
        }

        return $targetKeys;
    }

    /**
     * @return array<int, string>
     */
    private function allowedFieldKeys(Space $space, Asset $asset): array
    {
        return array_column($this->fieldResolver->getEffectiveClassificationFieldsForAsset($space, $asset), 'key');
    }

    /**
     * @param  array<int, string>  $fieldKeys
     * @param  array<int, string>  $languages
     * @return array<int, string>
     */
    private function allTargetKeys(array $fieldKeys, array $languages): array
    {
        $keys = [];

        foreach ($languages as $language) {
            foreach ($fieldKeys as $fieldKey) {
                $keys[] = "{$language}.{$fieldKey}";
            }
        }

        return $keys;
    }

    private function wantsTags(Asset $asset, bool $overwrite): bool
    {
        return $overwrite || ($asset->tags ?? []) === [];
    }

    /**
     * The tags the prompt carries. A taxonomy beyond the prompt's size gets
     * the tags matching the asset's hints first, then the most used ones.
     *
     * @return array{tags: array<string, string>, total: int} lower-cased tag name => tag id, and how many tags exist
     */
    private function tagTaxonomy(Asset $asset, ?string $altContext): array
    {
        $total = AssetTag::query()->count();

        if ($total <= self::MAX_TAGS_IN_PROMPT) {
            return ['tags' => $this->tagMap(AssetTag::query()->orderBy('name')->get(['id', 'name'])), 'total' => $total];
        }

        $terms = $this->relevanceTerms($asset, $altContext);
        $relevant = $terms === [] ? new Collection : AssetTag::query()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    AssetStructure::orWhereTagNameContains($query, $term);
                }
            })
            ->orderBy('name')
            ->limit(self::MAX_TAGS_IN_PROMPT)
            ->get(['id', 'name']);
        $popular = AssetTag::query()
            ->whereNotIn('id', $relevant->modelKeys())
            ->withCount('assets')
            ->orderByDesc('assets_count')
            ->orderBy('name')
            ->limit(self::MAX_TAGS_IN_PROMPT - $relevant->count())
            ->get(['id', 'name']);

        return ['tags' => $this->tagMap($relevant->concat($popular)), 'total' => $total];
    }

    /**
     * @param  iterable<AssetTag>  $tags
     * @return array<string, string> lower-cased tag name => tag id
     */
    private function tagMap(iterable $tags): array
    {
        $map = [];

        foreach ($tags as $tag) {
            $name = trim((string) $tag->name);

            if ($name !== '') {
                $map[mb_strtolower($name)] = $tag->id;
            }
        }

        return $map;
    }

    /**
     * Words of the asset's filename, folder, metadata and placement context.
     *
     * @return list<string>
     */
    private function relevanceTerms(Asset $asset, ?string $altContext): array
    {
        $hints = [
            Str::beforeLast((string) $asset->filename, '.'),
            $asset->relationLoaded('folder') ? (string) $asset->folder?->name : '',
            (string) $altContext,
            ...array_filter(Arr::flatten((array) data_get($asset->data, 'fields', [])), 'is_string'),
        ];
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(implode(' ', $hints)), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return \array_slice(array_values(array_unique(array_filter($words, fn (string $word) => mb_strlen($word) >= 3))), 0, self::MAX_RELEVANCE_TERMS);
    }

    /**
     * Existing tags the model named although the prompt's list left them out.
     *
     * @param  array<string, string>  $known  lower-cased tag name => tag id
     * @return array<string, string> lower-cased tag name => tag id
     */
    private function tagsNamedIn(string $content, array $known): array
    {
        $named = JsonExtractor::decode($content)['tags'] ?? null;
        $names = array_map(fn (string $name) => mb_strtolower(trim($name)), array_filter(\is_array($named) ? $named : [], 'is_string'));
        $names = \array_slice(array_values(array_diff($names, array_keys($known))), 0, self::MAX_TAGS_PER_ASSET * 4);

        if ($names === []) {
            return [];
        }

        return $this->tagMap(AssetTag::query()
            ->whereRaw('LOWER(name) IN ('.implode(',', array_fill(0, \count($names), '?')).')', $names)
            ->get(['id', 'name']));
    }

    /**
     * @return array<string, string> language key => display name
     */
    private function languageNames(Space $space): array
    {
        $names = [AssetMetadataFieldResolver::DEFAULT_LANGUAGE_KEY => (string) ($space->settings->default_language ?: 'en')];

        foreach ($space->settings->languages ?? [] as $language) {
            $code = $language['code'] ?? null;

            if (\is_string($code) && $code !== '' && ! isset($names[$code])) {
                $names[$code] = (string) ($language['name'] ?? $code);
            }
        }

        // Same keys, in the same order, as the asset data is stored under.
        return array_intersect_key($names, array_flip($this->fieldResolver->languageKeys($space)));
    }

    /**
     * The run for one image: the asset-classification skill (or a space's own
     * version of it) with the per-request instructions, the image and the weak
     * hints (filename, folder, existing values) as fenced context.
     *
     * @param  array{mime_type: string, data: string}  $imagePart
     * @param  array<int, string>  $languages
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $targetKeys
     * @param  array<int, string>  $tagNames  lower-cased taxonomy names
     * @param  int  $tagTotal  how many tags exist; more than `$tagNames` makes the list a subset
     */
    private function startRun(
        Space $space,
        User $actor,
        Asset $asset,
        array $imagePart,
        array $languages,
        array $fields,
        array $targetKeys,
        array $tagNames,
        SpaceAiConfig $config,
        ?string $altContext = null,
        bool $decorative = false,
        int $tagTotal = 0,
    ): StartRun {
        $languageNames = array_intersect_key($this->languageNames($space), array_flip($languages));
        $writes = array_unique(array_map(fn (string $key) => explode('.', $key, 2)[0], $targetKeys));

        $instructions = [
            (new SystemPromptBuilder)->assetClassificationInstructions($languages, $fields, $languageNames, $tagNames, $altContext, $decorative),
            'Generate editorial metadata for this image.',
            $targetKeys !== []
                ? 'Return values only for these exact keys: '.json_encode($targetKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : 'Do not return any field keys.',
            $tagNames !== [] ? 'Also return matching "tags".' : null,
            $tagNames !== [] && \count($tagNames) < $tagTotal
                ? 'The tag list shows '.\count($tagNames).' of '.$tagTotal.' existing tags. When another existing tag plainly fits, you may return its exact name; names that are no existing tag are dropped and reported.'
                : null,
        ];

        // Filename, folder and existing values are weak hints for names and
        // consistency across languages; they are user data and fenced as such.
        $context = array_filter([
            'filename' => Str::of($asset->filename)->beforeLast('.')->replace(['-', '_'], ' ')->trim()->value(),
            'folder' => $asset->relationLoaded('folder') ? $asset->folder?->name : null,
            'existing_values' => $this->existingValues($asset, $targetKeys),
        ]);

        return new StartRun(
            space: $space,
            actor: $actor,
            surface: 'asset',
            skill: $this->skills->resolve($space)['asset-classification'] ?? null,
            context: $context,
            config: $config,
            instructions: implode("\n\n", array_filter($instructions)),
            outputLocales: array_combine($writes, array_map(fn (string $language) => $this->localeOf($space, $language), $writes)),
            parts: [[
                'type' => 'image',
                'mime_type' => $imagePart['mime_type'],
                'data' => base64_encode($imagePart['data']),
            ]],
        );
    }

    /**
     * Values already present for keys that are not being generated, so a
     * German alt text lines up with the English one an editor wrote.
     *
     * @param  array<int, string>  $targetKeys
     * @return array<string, string>
     */
    private function existingValues(Asset $asset, array $targetKeys): array
    {
        $data = \is_array($asset->data) ? $asset->data : [];
        $fields = \is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $targets = array_flip($targetKeys);
        $existing = [];

        foreach ($fields as $language => $values) {
            if (! \is_array($values)) {
                continue;
            }

            foreach ($values as $field => $value) {
                if (\is_string($value) && trim($value) !== '' && ! isset($targets["{$language}.{$field}"])) {
                    $existing["{$language}.{$field}"] = mb_substr(trim($value), 0, 300);
                }
            }
        }

        return $existing;
    }

    /**
     * Records which keys were AI-written and by what, so editors and audits
     * can tell generated copy from authored copy.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $written
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $report  what stayed open, see {@see ClassificationReport}
     * @return array<string, mixed>
     */
    private function withProvenance(array $metadata, SpaceAiConfig $config, array $written, array $tags, array $report): array
    {
        $metadata['ai_classification'] = [
            'at' => now()->toIso8601String(),
            'model' => "{$config->driver}:{$config->model}",
            'fields' => $written,
            'tags' => $tags,
            'report' => ClassificationReport::summary($report),
        ];

        return $metadata;
    }

    /**
     * @return array{mime_type: string, data: string}
     *
     * @throws \RuntimeException
     */
    private function imagePart(Asset $asset, bool $highDetail = false): array
    {
        return $this->vision->image($asset, $highDetail ? self::HIGH_DETAIL_EDGE : self::MAX_EDGE);
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || (\is_string($value) && trim($value) === '');
    }
}
