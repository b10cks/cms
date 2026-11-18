<?php

namespace App\Services\Content;

use App\Actions\Content\UpdateContent;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\TranslationSource;
use App\Models\User;
use App\Services\Ai\Translation\TranslationLineage;
use App\Services\Ai\Translation\TranslationMemory;
use App\Services\Ai\Translation\TranslationUnits;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The translations of nested blocks, taken along when a block is copied, cut
 * or duplicated in the editor.
 *
 * Only an overlay entry has them: its translations store overrides matched to
 * the canonical entry by block id. `collect()` reads the overrides of blocks
 * for the clipboard, `apply()` writes them to the translations of the entry
 * the blocks were pasted into, under the ids the paste gave them.
 *
 * @phpstan-type Step array{field: string, id: string, block: string}
 * @phpstan-type Lineage array{unit: string, source_hash: string, source_text: string, origin: string}
 * @phpstan-type BlockTranslation array{item: array<string, mixed>, lineage?: list<Lineage>}
 */
class BlockTranslations
{
    private const ORIGINS = [
        TranslationSource::ORIGIN_AI,
        TranslationSource::ORIGIN_REVIEW,
        TranslationSource::ORIGIN_HISTORY,
    ];

    public function __construct(
        private readonly ContentI18nService $i18n,
        private readonly TranslationUnits $units,
        private readonly TranslationLineage $lineage,
        private readonly UpdateContent $updateContent,
    ) {}

    /**
     * The saved overrides of blocks in every translation of an entry, with the
     * lineage of their text. `unit` is the unit ID below the block: `text`,
     * `items.<block id>.title`.
     *
     * @param  list<string>  $blockIds
     * @return array<string, array<string, BlockTranslation>> block ID => language => translation
     */
    public function collect(Space $space, Content $content, array $blockIds): array
    {
        if (! $this->carriesTranslations($space, $content)) {
            return [];
        }

        $paths = $this->pathsTo($content->getCurrentContent(), $blockIds);
        if ($paths === []) {
            return [];
        }

        $schema = $content->block?->schema?->toArray() ?? [];
        $collected = [];

        foreach ($this->translations($space, $content) as $language => $translation) {
            $overrides = $translation->getCurrentContent();
            $items = array_filter(array_map(fn (array $path) => $this->itemAt($overrides, $path), $paths));
            if ($items === []) {
                continue;
            }

            // Unit ID => the block it belongs to and its ID below that block.
            $units = [];
            foreach ($this->units->texts($overrides, $schema) as $unitId => $text) {
                foreach (array_keys($items) as $blockId) {
                    $prefix = $this->unitPrefix($paths[$blockId]);
                    if (str_starts_with($unitId, $prefix) && ! TranslationUnits::isBlank($text)) {
                        $units[$unitId] = ['block' => $blockId, 'unit' => substr($unitId, \strlen($prefix)), 'text' => $text];
                    }
                }
            }

            $lineage = $this->lineage->of([[
                'source' => $content,
                'translation' => $translation,
                'targets' => array_map(fn (array $unit) => $unit['text'], $units),
            ]])[$translation->id] ?? [];

            foreach ($items as $blockId => $item) {
                $collected[$blockId][$language] = ['item' => $item, 'lineage' => []];
            }
            foreach ($lineage as $unitId => $origin) {
                $collected[$units[$unitId]['block']][$language]['lineage'][] = ['unit' => $units[$unitId]['unit'], ...$origin];
            }
        }

        return $collected;
    }

    /**
     * Write the translations of pasted blocks to the translations of the entry
     * they were pasted into. Blocks the saved entry does not hold are ignored:
     * they were removed again before the save. A language the entry has no
     * translation in, or whose translation does not validate with the pasted
     * values, is skipped and reported; the others are saved as drafts.
     *
     * @param  array<string, array<string, BlockTranslation>>  $pasted  block ID => language => translation
     * @return array{applied: list<string>, skipped: list<array{language: string, reason: 'missing'|'invalid'}>}
     */
    public function apply(Space $space, Content $content, array $pasted, Authenticatable|User|null $owner): array
    {
        $report = ['applied' => [], 'skipped' => []];
        // Read again: the save before this left the model with a stale version and settings.
        $content = $content->fresh(['block', 'current_version']);
        if ($pasted === [] || ! $this->carriesTranslations($space, $content)) {
            return $report;
        }

        $paths = $this->pathsTo($content->getCurrentContent(), array_keys($pasted));
        $pasted = array_intersect_key($pasted, $paths);

        $translations = $this->translations($space, $content);
        $languages = array_unique(array_merge(...array_values(array_map(array_keys(...), $pasted))));

        foreach ($languages as $language) {
            $language = (string) $language;
            $translation = $translations->get($language);
            if ($translation === null) {
                $report['skipped'][] = ['language' => $language, 'reason' => 'missing'];

                continue;
            }

            $blocks = array_filter(array_map(fn (array $byLanguage) => $byLanguage[$language] ?? null, $pasted));

            try {
                $translation->getConnection()->transaction(fn () => $this->write($space, $content, $translation, $blocks, $paths, $owner));
                $report['applied'][] = $language;
            } catch (ValidationException) {
                $report['skipped'][] = ['language' => $language, 'reason' => 'invalid'];
            }
        }

        return $report;
    }

    /**
     * @param  array<string, BlockTranslation>  $blocks  block ID => translation
     * @param  array<string, list<Step>>  $paths
     */
    private function write(Space $space, Content $source, Content $translation, array $blocks, array $paths, Authenticatable|User|null $owner): void
    {
        // Locked before its content is read, so a save in between cannot be lost.
        $translation = Content::query()->lockForUpdate()->findOrFail($translation->id);
        $overrides = $translation->getCurrentContent();

        // Outer blocks first: a block's item replaces what the translation held below it.
        uksort($blocks, fn (string $a, string $b) => \count($paths[$a]) <=> \count($paths[$b]));
        foreach ($blocks as $blockId => $block) {
            $overrides = $this->put($overrides, $paths[$blockId], $block['item']);
        }

        $this->updateContent->execute(['content' => $overrides, 'force' => true], $translation, $space, $owner, i18nValidated: true);

        $texts = $this->units->texts($translation->fresh()->getCurrentContent(), $source->block?->schema?->toArray() ?? []);

        foreach ($blocks as $blockId => $block) {
            foreach ($block['lineage'] ?? [] as $lineage) {
                $unitId = $this->unitPrefix($paths[$blockId]).$lineage['unit'];
                $text = $texts[$unitId] ?? '';
                if (TranslationUnits::isBlank($text) || ! \in_array($lineage['origin'], self::ORIGINS, true)) {
                    continue;
                }

                TranslationSource::query()->create([
                    'content_id' => $translation->id,
                    'source_content_id' => $source->id,
                    'field_path' => $unitId,
                    'source_hash' => $lineage['source_hash'],
                    'source_text' => (string) ($lineage['source_text'] ?? ''),
                    'target_hash' => TranslationMemory::hash($text),
                    'origin' => $lineage['origin'],
                ]);
            }
        }
    }

    /** Overrides are matched by block id only where the canonical entry of an overlay family owns the structure. */
    private function carriesTranslations(Space $space, Content $content): bool
    {
        return $content->i18n_parent_id === null
            && $this->i18n->resolveEffectiveModeForCanonical($space, $content) === 'overlay';
    }

    /** @return Collection<string, Content> language => translation, for the enabled languages */
    private function translations(Space $space, Content $content): Collection
    {
        return $this->i18n->getFamily($content)
            ->filter(fn (Content $row) => $row->i18n_parent_id !== null
                && \in_array($row->language_iso, $space->settings->getEnabledLanguages(), true))
            ->keyBy('language_iso');
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<string>  $blockIds
     * @return array<string, list<Step>> block ID => path, for the blocks the content holds
     */
    private function pathsTo(array $content, array $blockIds): array
    {
        $paths = [];
        foreach ($blockIds as $id) {
            $path = $this->pathTo($content, $id);
            if ($path !== null) {
                $paths[$id] = $path;
            }
        }

        return $paths;
    }

    /**
     * The blocks lists that lead to a block, outermost first.
     *
     * @param  array<string, mixed>  $node
     * @return list<Step>|null
     */
    private function pathTo(array $node, string $id): ?array
    {
        foreach ($node as $field => $items) {
            if (! \is_array($items) || ! array_is_list($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! \is_array($item) || ! \is_string($item['id'] ?? null) || ! \is_string($item['block'] ?? null)) {
                    continue;
                }

                $step = ['field' => (string) $field, 'id' => $item['id'], 'block' => $item['block']];
                if ($item['id'] === $id) {
                    return [$step];
                }

                $rest = $this->pathTo($item, $id);
                if ($rest !== null) {
                    return [$step, ...$rest];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<Step>  $path
     * @return array<string, mixed>|null
     */
    private function itemAt(array $content, array $path): ?array
    {
        foreach ($path as $step) {
            $items = \is_array($content[$step['field']] ?? null) ? $content[$step['field']] : [];
            $content = collect($items)->first(fn ($item) => \is_array($item) && ($item['id'] ?? null) === $step['id']);
            if ($content === null) {
                return null;
            }
        }

        return $content;
    }

    /**
     * Overrides with `$item` at `$path`. Ancestors a translation holds nothing
     * for yet are added as bare items, which is how it stores a nested override.
     *
     * @param  array<string, mixed>  $content
     * @param  non-empty-list<Step>  $path
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function put(array $content, array $path, array $item): array
    {
        $step = array_shift($path);
        $identity = ['id' => $step['id'], 'block' => $step['block']];
        $items = \is_array($content[$step['field']] ?? null) ? array_values($content[$step['field']]) : [];
        $index = array_find_key($items, fn ($entry) => \is_array($entry) && ($entry['id'] ?? null) === $step['id']) ?? \count($items);

        $items[$index] = $path === []
            ? [...$item, ...$identity]
            : $this->put($items[$index] ?? $identity, $path, $item);
        $content[$step['field']] = $items;

        return $content;
    }

    /**
     * The unit ID prefix of a block's fields: `body.<block id>.`.
     *
     * @param  list<Step>  $path
     */
    private function unitPrefix(array $path): string
    {
        return implode('', array_map(fn (array $step) => "{$step['field']}.{$step['id']}.", $path));
    }
}
