<?php

namespace App\Services\Ai\Translation;

use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\Space\Content;
use App\Services\Ai\Changesets\ContentTarget;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Changesets\TargetSnapshot;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Knowledge\PolicySnapshot;
use App\Services\Ai\Run\ToolError;
use App\Services\Content\ContentHierarchyValidator;
use App\Services\Content\ContentI18nValidator;
use App\Services\Content\RichText\ProseMirrorHtmlConverter;
use App\Services\Content\Schema\ContentSchemaValidator;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Turns translated units into changeset items on the right row, for the tool to
 * write to its draft. Only the units of a {@see TranslationTask} are accepted, so
 * unchanged human translations are never overwritten. Units are written into the
 * translation's own field values, keeping every other unit of a field; an
 * overlay translation gets a stub for a block it does not hold yet. A missing
 * translation becomes one create item in the source's family.
 */
class TranslationProposer
{
    public const MAX_UNITS = 200;

    private ?PolicySnapshot $policy = null;

    public function __construct(
        private readonly TranslationUnits $units,
        private readonly TranslationLineage $lineage,
        private readonly ProseMirrorHtmlConverter $richtext,
        private readonly ContentTarget $contents,
        private readonly ContentI18nValidator $i18nValidator,
        private readonly ContentHierarchyValidator $hierarchy,
        private readonly ContentSchemaValidator $schemaValidator,
        private readonly GlossaryChecker $checker,
        private readonly KnowledgeResolver $knowledge,
    ) {}

    /**
     * @param  array<string, mixed>  $texts  unit ID => translated text
     * @param  string|null  $name  name of a new translation entry
     * @param  AiRun|null  $run  the run whose pinned policy checks the wording; null checks against the live state
     *
     * @throws ToolError listing every problem, for the model to fix
     */
    public function prepare(Space $space, TranslationTask $task, array $texts, ?string $name = null, ?string $slug = null, ?AiRun $run = null): PreparedTranslation
    {
        $this->policy = $this->knowledge->policy($space, $run);

        $destination = $task->destination;
        $creating = $destination->translation === null;
        $name = trim((string) $name);

        if (! $task->createApproved && $creating) {
            throw new ToolError("This entry has no {$destination->locale} translation, and this run was not approved to create translations. Leave it.");
        }
        if ($creating && $name === '') {
            throw new ToolError("This entry has no {$destination->locale} translation yet, so this call creates one: give its name in {$destination->locale}.");
        }
        if (($texts === [] && ! $creating) || \count($texts) > self::MAX_UNITS) {
            throw new ToolError('Pass between 1 and '.self::MAX_UNITS.' units.');
        }

        $this->check($task, $texts);

        $schema = $destination->source->block?->schema?->toArray() ?? [];
        $sourceDoc = $destination->source->getCurrentContent();
        $original = $creating ? [] : $destination->translation->getCurrentContent();
        $doc = match (true) {
            ! $creating => $original,
            $destination->overlay() => [],
            default => $this->blanked($sourceDoc, $schema),
        };

        $fields = [];
        foreach ($texts as $id => $text) {
            $unit = $task->units[$id];
            $fields[$unit['field']]['type'] = $unit['type'];
            $fields[$unit['field']]['units'][$id] = (string) $text;
        }

        $problems = [];
        $lists = [];
        foreach ($fields as $path => ['type' => $type, 'units' => $given]) {
            [$doc, $lists[$path]] = $this->ensureItems($doc, $sourceDoc, (string) $path);
            $current = FieldPath::get($doc, (string) $path);
            $base = $current;

            if (\in_array($type, TranslationUnits::COMPOSITE_TYPES, true) && $this->emptyComposite($current)) {
                // An empty meta or table is replaced as a whole: translate all of it at once.
                $all = array_keys(array_filter($task->units, fn (array $unit) => $unit['field'] === $path));
                $left = array_diff($all, array_keys($given));
                if ($left !== []) {
                    $problems[] = "{$path} is stored as a whole on the translation and is empty there: translate ".implode(', ', $all).' together.';
                }
                $base = $this->blankedComposite(FieldPath::get($sourceDoc, (string) $path), $type);
            }

            $doc = FieldPath::set($doc, (string) $path, $this->write($type, (string) $path, $base, $given));
        }

        if ($problems !== []) {
            throw new ToolError("The translation is incomplete:\n- ".implode("\n- ", $problems));
        }

        $written = $this->units->texts($doc, $schema);
        $unitRow = fn (string $id) => [
            'field_path' => $id,
            'source_text' => $task->units[$id]['source'],
            'target_text' => $written[$id] ?? (string) $texts[$id],
            'reason' => $task->units[$id]['reason'],
            'previous_source_text' => $task->units[$id]['previous_source'] ?? null,
        ];

        if ($creating) {
            $item = $this->createItem($space, $destination, $doc, $name, $slug);
            $lineage = array_map($unitRow, array_map('strval', array_keys($texts)));

            return new PreparedTranslation($destination, [$item], [$lineage], [$this->findings($destination, $lineage)]);
        }

        // A field inside a block the translation lacked is written with its whole block list.
        $patches = [];
        foreach (array_keys($fields) as $path) {
            $patches[$lists[$path] ?? (string) $path] = true;
        }
        $paths = array_values(array_filter(array_keys($patches), fn (string $path) => ! array_filter(
            array_keys($patches),
            fn (string $other) => $other !== $path && str_starts_with($path, "{$other}."),
        )));
        $values = array_combine($paths, array_map(fn (string $path) => FieldPath::get($doc, $path), $paths));

        $translation = $destination->translation;
        $types = $this->contents->validate($space, new TargetSnapshot($translation, (string) $translation->name, $original, $translation->current_version_id), $values);

        $items = [];
        $lineage = [];
        $findings = [];
        foreach ($paths as $path) {
            $covered = array_values(array_filter(array_map('strval', array_keys($texts)), fn (string $id) => $task->units[$id]['field'] === $path || str_starts_with($task->units[$id]['field'], "{$path}.")));
            $rows = array_map($unitRow, $covered);

            $items[] = [
                'operation' => AiChangesetItem::OPERATION_UPDATE,
                'target_type' => AiChangesetItem::TARGET_CONTENT,
                'target_id' => $translation->id,
                'target_label' => (string) $translation->name,
                'base_version_id' => $translation->current_version_id,
                'path' => $path,
                'field_type' => $types[$path] ?? null,
                'before' => FieldPath::get($original, $path),
                'after' => $values[$path],
            ];
            $lineage[] = $rows;
            $findings[] = $this->findings($destination, $rows);
        }

        return new PreparedTranslation($destination, $items, $lineage, $findings);
    }

    /**
     * Store lineage and glossary findings of the recorded items. Hints are not
     * stored: they are returned, by item ID, for the agent.
     *
     * @param  list<AiChangesetItem>  $items  the recorded items, in the order of `$prepared->items`
     * @return array<string, list<array<string, string>>>
     */
    public function persist(PreparedTranslation $prepared, array $items): array
    {
        $rows = [];
        $hints = [];
        foreach ($items as $index => $item) {
            foreach ($prepared->lineage[$index] ?? [] as $unit) {
                $rows[] = [
                    'content_id' => $prepared->destination->translation?->id,
                    'changeset_item_id' => $item->id,
                    'source_content_id' => $prepared->destination->source->id,
                    'source_version_id' => $prepared->destination->source->current_version_id,
                    ...$unit,
                ];
            }

            $found = $prepared->findings[$index] ?? [];
            $findings = [...($item->glossary_findings ?? []), ...GlossaryChecker::blocking($found)];
            if ($findings !== []) {
                $item->update(['glossary_findings' => array_values(array_unique($findings, SORT_REGULAR))]);
            }
            if (($itemHints = GlossaryChecker::hints($found)) !== []) {
                $hints[$item->id] = $itemHints;
            }

            if ($this->policy?->isPinned()) {
                $source = implode("\n", array_column($prepared->lineage[$index] ?? [], 'source_text'));
                $applied = GlossaryMatcher::for($this->policy->concepts)->match($source, $prepared->destination->sourceLocale);
                $item->update(['review' => [
                    ...($item->review ?? []),
                    'policy' => $this->policy->provenance($applied),
                    // Only here are the destination's locales known.
                    'locales' => ['source' => $prepared->destination->sourceLocale, 'target' => $prepared->destination->locale],
                ]]);
            }
        }

        $this->lineage->recordProposals($rows);

        return $hints;
    }

    /**
     * @param  array<string, mixed>  $texts
     *
     * @throws ToolError
     */
    private function check(TranslationTask $task, array $texts): void
    {
        $problems = [];

        foreach ($texts as $id => $text) {
            $id = (string) $id;
            $unit = $task->units[$id] ?? null;

            if ($unit === null) {
                $problems[] = "{$id}: ".match ($task->held[$id] ?? null) {
                    TranslationTask::HELD_CURRENT => 'already translated, and its source has not changed since. Leave it as it is.',
                    TranslationTask::HELD_INHERITED => 'filled by a fallback language. Leave it empty.',
                    TranslationTask::HELD_NOT_IN_TRANSLATION => 'the translation does not have this block. Adding blocks to a standalone translation is left to an editor.',
                    TranslationTask::HELD_NOT_IN_PLAN => 'this kind of change is not part of the approved plan. Leave it.',
                    TranslationTask::HELD_GENERATED => 'the approved plan generates this value from the translation. Leave it out and write it with propose_translation_metadata.',
                    default => 'no such unit to translate. Use the unit IDs from get_translation_task.',
                };

                continue;
            }

            if (! \is_string($text) || TranslationUnits::isBlank($text)) {
                $problems[] = "{$id}: give the translation as non-empty text.";

                continue;
            }

            foreach (TranslationIntegrity::problems($unit['source'], $text) as $problem) {
                $problems[] = "{$id}: {$problem}.";
            }
        }

        if ($problems !== []) {
            throw new ToolError("Nothing was written:\n- ".implode("\n- ", $problems));
        }
    }

    /**
     * Stub blocks an overlay translation lacks along the path, keeping their IDs.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $sourceDoc
     * @return array{0: array<string, mixed>, 1: string|null} the document and the outermost block list a stub went into
     */
    private function ensureItems(array $doc, array $sourceDoc, string $path): array
    {
        $segments = explode('.', $path);
        $inserted = null;

        for ($i = 1; $i < \count($segments) - 1; $i += 2) {
            $listPath = implode('.', \array_slice($segments, 0, $i));
            $itemId = $segments[$i];
            $list = FieldPath::get($doc, $listPath);
            $list = \is_array($list) ? array_values($list) : [];

            if (array_filter($list, fn ($item) => \is_array($item) && ($item['id'] ?? null) === $itemId) !== []) {
                continue;
            }

            $sourceItem = FieldPath::get($sourceDoc, "{$listPath}.{$itemId}");
            $list[] = ['id' => $itemId, 'block' => \is_array($sourceItem) ? ($sourceItem['block'] ?? null) : null];
            $doc = FieldPath::set($doc, $listPath, $list);
            $inserted ??= $listPath;
        }

        return [$doc, $inserted];
    }

    /**
     * A field value with the given units written in.
     *
     * @param  array<string, string>  $given  unit ID => text
     */
    private function write(string $type, string $path, mixed $base, array $given): mixed
    {
        if ($type === 'richtext') {
            return $this->richtext->toDoc((string) reset($given));
        }

        if (! \in_array($type, TranslationUnits::COMPOSITE_TYPES, true)) {
            return (string) reset($given);
        }

        $value = \is_array($base) ? $base : [];
        foreach ($given as $id => $text) {
            $rest = explode('.', substr((string) $id, \strlen($path) + 1));

            if ($type === 'meta') {
                $value[$rest[0]] = $text;
            } elseif ($rest[0] === 'header') {
                $value['header'][$rest[1]] = $text;
            } else {
                $rows = \is_array($value['rows'] ?? null) ? $value['rows'] : [];
                foreach ($rows as $index => $row) {
                    $rowId = \is_array($row) && \is_string($row['id'] ?? null) && $row['id'] !== '' ? $row['id'] : (string) $index;
                    if (\is_array($row) && $rowId === $rest[1]) {
                        $rows[$index]['cells'][$rest[2]] = $text;
                    }
                }
                $value['rows'] = $rows;
            }
        }

        return $value;
    }

    private function emptyComposite(mixed $value): bool
    {
        if (! \is_array($value) || $value === []) {
            return true;
        }

        $text = array_filter(array_map(fn ($part) => \is_string($part) ? trim($part) : null, Arr::flatten($value)));

        return $text === [];
    }

    /** The source's meta or table with its text taken out, keeping images, flags and row structure. */
    public function blankedComposite(mixed $value, string $type): array
    {
        $value = \is_array($value) ? $value : [];

        if ($type === 'meta') {
            foreach (['title', 'description', 'ogTitle', 'ogDescription'] as $key) {
                if (\array_key_exists($key, $value)) {
                    $value[$key] = '';
                }
            }

            return $value;
        }

        foreach (\is_array($value['header'] ?? null) ? $value['header'] : [] as $key => $cell) {
            $value['header'][$key] = \is_string($cell) ? '' : $cell;
        }
        foreach (\is_array($value['rows'] ?? null) ? $value['rows'] : [] as $index => $row) {
            foreach (\is_array($row['cells'] ?? null) ? $row['cells'] : [] as $key => $cell) {
                $value['rows'][$index]['cells'][$key] = \is_string($cell) ? '' : $cell;
            }
        }

        return $value;
    }

    /**
     * The source document with every translatable text emptied: the start of
     * a standalone translation. Structure and other values stay.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function blanked(array $doc, array $schema): array
    {
        foreach ($this->units->collect($doc, $schema) as $unit) {
            $ref = &$doc;
            foreach ($unit['path'] as $key) {
                if (! \is_array($ref)) {
                    continue 2;
                }
                $ref = &$ref[$key];
            }
            $ref = $unit['type'] === 'richtext' ? null : '';
            unset($ref);
        }

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     *
     * @throws ToolError when the new entry would not save
     */
    private function createItem(Space $space, TranslationDestination $destination, array $content, string $name, ?string $slug): array
    {
        $source = $destination->source;
        $data = [
            'block_id' => $source->block_id,
            'name' => $name,
            ...($slug !== null && trim($slug) !== '' ? ['slug' => trim($slug)] : []),
            'parent_id' => $destination->parentId,
            'language_iso' => $destination->locale,
            'i18n_parent_id' => $source->id,
            'content' => $content,
        ];

        $problems = $this->i18nValidator->validate($space, $data);

        try {
            $parent = $destination->parentId !== null ? Content::query()->with('block')->find($destination->parentId) : null;
            $this->hierarchy->validatePlacement($space, $source->block, $parent, null, $destination->locale);
        } catch (ValidationException $e) {
            $problems = [...$problems, ...array_map(fn (array $messages) => implode(' ', $messages), $e->errors())];
        }

        $result = $this->schemaValidator->validateSubmission($space, $source->block, $content, null, $destination->locale, $source->id, 'save');
        // Creating does not force: a required field left empty would refuse the write.
        foreach ([...$result->errors, ...$result->warnings] as $key => $messages) {
            $problems[$key] = implode(' ', (array) $messages);
        }

        if ($problems !== []) {
            throw new ToolError("The new translation is invalid:\n- ".implode("\n- ", array_map(
                fn ($key, $message) => "{$key}: ".(\is_array($message) ? implode(' ', $message) : $message),
                array_keys($problems),
                $problems,
            )));
        }

        return [
            'operation' => AiChangesetItem::OPERATION_CREATE,
            'target_type' => AiChangesetItem::TARGET_CONTENT,
            'target_label' => $name,
            'after' => [...$data, 'content' => $result->content],
        ];
    }

    /**
     * @param  list<array{source_text: string, target_text: string}>  $units
     * @return list<array<string, string>>
     */
    private function findings(TranslationDestination $destination, array $units): array
    {
        if ($this->policy->concepts === []) {
            return [];
        }

        $findings = [];
        foreach ($units as $unit) {
            if ($unit['target_text'] === '') {
                continue;
            }
            $findings = [...$findings, ...$this->checker->check($unit['source_text'], $unit['target_text'], $destination->sourceLocale, $destination->locale, $this->policy->concepts)];
        }

        return array_values(array_unique($findings, SORT_REGULAR));
    }
}
