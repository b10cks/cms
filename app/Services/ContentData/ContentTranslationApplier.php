<?php

namespace App\Services\ContentData;

use App\Actions\Content\CreateContent;
use App\Actions\Content\PublishContent;
use App\Actions\Content\UpdateContent;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\ContentTranslationImportMode;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\ContentI18nService;
use App\Services\Content\RichText\ProseMirrorHtmlConverter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

/**
 * Applies parsed translation values back into the correct per-language content rows.
 *
 * Values are located in the target tree by re-walking it with the same stable-id
 * scheme used for extraction, so writes land on the right field regardless of block
 * ordering. Missing language rows are cloned from the canonical structure when
 * allowed; each row is persisted as a draft or published according to the mode.
 */
class ContentTranslationApplier
{
    /** @var array<int, array<string, mixed>> */
    private array $successes = [];

    /** @var array<int, array<string, mixed>> */
    private array $changes = [];

    /** @var array<int, string> */
    private array $ignoredFields = [];

    /** @var array<int, array<string, mixed>> */
    private array $errors = [];

    public function __construct(
        private readonly ContentTranslationExtractor $extractor,
        private readonly ContentI18nService $i18n,
        private readonly ProseMirrorHtmlConverter $richtext,
        private readonly CreateContent $createContent,
        private readonly UpdateContent $updateContent,
        private readonly PublishContent $publishContent,
    ) {}

    /**
     * @param  array<int, array{content_id: string, targets: array<string, array<string, string>>}>  $documents
     * @param  bool  $allowSourceEdits  Also apply values for the default language (written onto the canonical row).
     * @param  bool  $applyEmpty  Treat empty strings as intentional clears instead of "no value provided".
     */
    public function apply(
        Space $space,
        array $documents,
        ContentTranslationImportMode $mode,
        bool $createMissing,
        Authenticatable $owner,
        bool $allowSourceEdits = false,
        bool $applyEmpty = false,
    ): ImportResult {
        $this->successes = [];
        $this->changes = [];
        $this->ignoredFields = [];
        $this->errors = [];

        $enabledLanguages = $space->settings->getEnabledLanguages();
        $defaultLanguage = $space->settings->getDefaultLanguage();

        foreach ($documents as $document) {
            $contentId = (string) ($document['content_id'] ?? '');
            $targets = $document['targets'] ?? [];

            if ($contentId === '' || $targets === []) {
                continue;
            }

            $canonical = $this->resolveCanonical($contentId);

            if ($canonical === null) {
                $this->errors[] = ['content_id' => $contentId, 'message' => 'Content not found or not a canonical document'];

                continue;
            }

            $rootSchema = $canonical->block?->schema?->toArray() ?? [];

            foreach ($targets as $language => $values) {
                $language = (string) $language;

                $isSourceLanguage = $language === $defaultLanguage;

                if (($isSourceLanguage && ! $allowSourceEdits) || ! \in_array($language, $enabledLanguages, true)) {
                    $this->ignoredFields[] = $language;

                    continue;
                }

                if (! \is_array($values) || $values === []) {
                    continue;
                }

                $this->applyLanguage($space, $canonical, $rootSchema, $language, $values, $mode, $createMissing, $owner, $applyEmpty, $isSourceLanguage);
            }
        }

        return new ImportResult($this->successes, $this->changes, $this->ignoredFields, $this->errors);
    }

    /**
     * @param  array<string, mixed>  $rootSchema
     * @param  array<string, string>  $values
     */
    private function applyLanguage(
        Space $space,
        Content $canonical,
        array $rootSchema,
        string $language,
        array $values,
        ContentTranslationImportMode $mode,
        bool $createMissing,
        Authenticatable $owner,
        bool $applyEmpty = false,
        bool $isSourceLanguage = false,
    ): void {
        try {
            $family = $this->i18n->getFamily($canonical);
            /** @var Content|null $row */
            $row = $family->firstWhere('language_iso', $language);

            if ($row === null && ! $createMissing) {
                $this->ignoredFields[] = $language;

                return;
            }

            $tree = $row !== null ? $row->getCurrentContent() : $canonical->getCurrentContent();
            $unitMap = $this->extractor->collectUnits($tree, $rootSchema, includeNonTranslatable: true);

            $appliedChanges = [];
            foreach ($values as $id => $value) {
                $id = (string) $id;

                if (! isset($unitMap[$id])) {
                    $this->ignoredFields[] = $id;

                    continue;
                }

                // Non-translatable fields only exist on the source row — the overlay
                // merge always takes them from the base, so target-language writes
                // would be dead data.
                if (! ($unitMap[$id]['translatable'] ?? true) && ! $isSourceLanguage) {
                    $this->ignoredFields[] = $id;

                    continue;
                }

                // ConvertEmptyStringsToNull turns submitted clears into null.
                if ($applyEmpty && $value === null) {
                    $value = '';
                }

                if (! \is_string($value) || ($value === '' && ! $applyEmpty)) {
                    continue;
                }

                $unit = $unitMap[$id];
                $converted = $this->convertValue($unit['type'], $value);

                if ($this->valueAtPath($tree, $unit['path']) === $converted) {
                    continue;
                }

                $this->setByPath($tree, $unit['path'], $converted);
                $appliedChanges[] = ['field' => $id, 'label' => $unit['label']];
            }

            if ($appliedChanges === []) {
                return;
            }

            $targetContent = $row ?? $this->createTranslationRow($space, $canonical, $language, $owner);

            $this->persist($space, $targetContent, $language, $tree, $mode, $owner);

            $this->changes[] = [
                'content_id' => $canonical->id,
                'language' => $language,
                'name' => $canonical->name,
                'changes' => $appliedChanges,
            ];
            $this->successes[] = [
                'content_id' => $canonical->id,
                'language' => $language,
                'name' => $canonical->name,
            ];
        } catch (\Throwable $e) {
            Log::error('Content translation import error', [
                'content_id' => $canonical->id,
                'language' => $language,
                'error' => $e->getMessage(),
            ]);

            $this->errors[] = [
                'content_id' => $canonical->id,
                'language' => $language,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function resolveCanonical(string $contentId): ?Content
    {
        return Content::query()
            ->with('block')
            ->whereNull('i18n_parent_id')
            ->whereKey($contentId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $tree
     */
    private function persist(
        Space $space,
        Content $content,
        string $language,
        array $tree,
        ContentTranslationImportMode $mode,
        Authenticatable $owner,
    ): void {
        $data = [
            'content' => $tree,
            'language_iso' => $language,
            'force' => true,
            'message' => 'Translation import',
        ];

        if ($mode === ContentTranslationImportMode::PUBLISH) {
            // Publish mass-assigns leftover data keys onto the model; `force` is not fillable.
            unset($data['force']);
            $this->publishContent->execute($data, $content, $space, $owner);

            return;
        }

        $this->updateContent->execute($data, $content, $space, $owner);
    }

    private function createTranslationRow(
        Space $space,
        Content $canonical,
        string $language,
        Authenticatable $owner,
    ): Content {
        $content = new Content;

        $this->createContent->execute([
            'name' => $canonical->name,
            'slug' => $canonical->slug,
            'block_id' => $canonical->block_id,
            'language_iso' => $language,
            'i18n_parent_id' => $canonical->id,
            'parent_id' => $this->resolveParentForLanguage($canonical, $language),
            'content' => [],
            'force' => true,
        ], $content, $space, $owner);

        return $content;
    }

    private function resolveParentForLanguage(Content $canonical, string $language): ?string
    {
        if ($canonical->parent_id === null) {
            return null;
        }

        $parent = Content::query()->whereKey($canonical->parent_id)->first();

        if ($parent === null) {
            return $canonical->parent_id;
        }

        return $this->i18n->getFamily($parent)->firstWhere('language_iso', $language)?->id ?? $canonical->parent_id;
    }

    private function convertValue(string $type, string $value): mixed
    {
        if ($type === 'richtext') {
            return $this->richtext->toDoc($value);
        }

        if ($type === 'number' && is_numeric($value)) {
            return $value + 0;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  array<int, string|int>  $path
     */
    private function valueAtPath(array $tree, array $path): mixed
    {
        $ref = $tree;

        foreach ($path as $key) {
            if (! \is_array($ref) || ! \array_key_exists($key, $ref)) {
                return null;
            }

            $ref = $ref[$key];
        }

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  array<int, string|int>  $path
     */
    private function setByPath(array &$tree, array $path, mixed $value): void
    {
        $ref = &$tree;
        $lastKey = array_key_last($path);

        foreach ($path as $index => $key) {
            if ($index === $lastKey) {
                $ref[$key] = $value;

                return;
            }

            if (! isset($ref[$key]) || ! \is_array($ref[$key])) {
                $ref[$key] = [];
            }

            $ref = &$ref[$key];
        }
    }
}
