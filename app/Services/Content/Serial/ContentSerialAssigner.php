<?php

namespace App\Services\Content\Serial;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentSerial;
use App\Models\Space\ContentVersion;
use App\Services\Content\Schema\SchemaField;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Applies `serial` fields to a content payload.
 *
 * Everything that creates, moves, restores or backfills content goes through
 * here rather than talking to the allocator directly, so the rules that make a
 * serial an identifier — assigned once, shared across translations, stable on
 * move — live in exactly one place.
 */
class ContentSerialAssigner
{
    public function __construct(
        protected readonly SerialAllocator $allocator,
        protected readonly ScopeKeyBuilder $scopeKeys,
        protected readonly TemplateRenderer $renderer,
    ) {}

    /**
     * Fill every serial field of a not-yet-persisted entry.
     *
     * @param  array<string, mixed>  $values  validated content payload
     * @return array<string, mixed>
     */
    public function assignOnCreate(
        Space $space,
        Block $block,
        Content $content,
        ?Content $parent,
        array $values,
        bool $adoptExistingValues = false,
    ): array {
        $serialFields = SerialFieldConfig::collect($block->schema);

        if ($serialFields === []) {
            return $values;
        }

        $inherited = $this->inheritedValues($content, array_keys($serialFields));
        $context = $this->context($block, $parent, (string) $content->language_iso, $values);
        $gapStrategy = $this->gapStrategy($space);

        foreach ($serialFields as $key => ['config' => $config, 'field' => $field]) {
            // Already reserved. Makes the whole method idempotent, which is what
            // lets a backfill be re-run without renumbering anything.
            if ($this->hasLedgerRow((string) $content->id, $key)) {
                continue;
            }

            // Translations share the canonical entry's identifier: same thing,
            // different language. Allocating here would hand the same house two
            // numbers.
            if (array_key_exists($key, $inherited)) {
                $values[$key] = $inherited[$key];

                continue;
            }

            // A translation whose canonical has no value yet (the field was
            // added after the canonical was created) must not draw its own
            // number either — it would permanently diverge from the canonical
            // once that one is backfilled. The backfill fills both.
            if ($content->i18n_parent_id) {
                continue;
            }

            // A value the field is allowed to carry: the editor's own input on
            // an editable field, or a pre-existing value being adopted by a
            // backfill. Anything a client posts for a readonly field on create
            // is ignored — the allocator owns that value.
            $submitted = $values[$key] ?? null;

            if (($config->editable || $adoptExistingValues) && $this->filled($submitted)) {
                $this->claimExistingValue($config, $field, $context, (string) $content->id, (string) $submitted);

                continue;
            }

            $allocation = $this->allocator->allocate(
                $config,
                $field,
                $context,
                (string) $content->id,
                $gapStrategy,
            );

            $values[$key] = $allocation->value;
            $context = $context->withValues($values);
        }

        return $values;
    }

    /**
     * Re-run allocation for fields configured with `on_move: reallocate`.
     *
     * @return array<string, string> newly assigned values, keyed by field
     */
    public function reallocateOnMove(Space $space, Block $block, Content $content, ?Content $parent): array
    {
        $serialFields = SerialFieldConfig::collect($block->schema);
        $assigned = [];
        $values = $content->getCurrentContent();
        // `createdAt` is passed so `{date:…}` tokens and year/month scopes keep
        // rendering against the entry's creation date, exactly as a restore or
        // a re-issue would — a move must not silently change the year.
        $context = $this->context($block, $parent, (string) $content->language_iso, $values, $content->created_at);
        $gapStrategy = $this->gapStrategy($space);

        foreach ($serialFields as $key => ['config' => $config, 'field' => $field]) {
            if ($config->onMove !== 'reallocate') {
                continue;
            }

            $this->allocator->release((string) $content->id, $key);

            $allocation = $this->allocator->allocate(
                $config,
                $field,
                $context,
                (string) $content->id,
                $gapStrategy,
            );

            $assigned[$key] = $allocation->value;
            $values[$key] = $allocation->value;
            $context = $context->withValues($values);
        }

        return $assigned;
    }

    /**
     * Reconcile the ledger with edited values on an existing entry.
     *
     * Only editable fields can legitimately change after creation — readonly
     * ones are restored from the stored entry by the schema validator before
     * this runs. The reservation keeps its number and scope (the counter slot
     * was drawn at creation and editing must not disturb it); only the rendered
     * value moves, and the ledger's unique index keeps guarding it.
     *
     * @param  array<string, mixed>  $values  validated content payload
     * @return array<string, mixed>
     *
     * @throws SerialCollisionException when an edited value is already taken
     */
    public function syncEditedValues(Block $block, Content $content, array $values): array
    {
        foreach (SerialFieldConfig::collect($block->schema) as $key => ['config' => $config, 'field' => $field]) {
            if (! $config->editable) {
                continue;
            }

            $serial = ContentSerial::query()
                ->where('content_id', (string) $content->id)
                ->where('field_key', $key)
                ->first();

            // No reservation to reconcile: a translation (the canonical owns
            // the row) or an entry that predates the ledger. The backfill
            // command is the tool for the latter.
            if (! $serial) {
                continue;
            }

            $submitted = $values[$key] ?? null;

            // Clearing the field asks for the generated value back — an
            // identifier cannot be un-assigned.
            if (! $this->filled($submitted)) {
                $values[$key] = $serial->value;

                continue;
            }

            $submitted = (string) $submitted;

            if ($submitted === $serial->value) {
                continue;
            }

            if ($serial->unique_key !== null && $this->valueTaken($serial->unique_key, $submitted, (string) $content->id)) {
                throw new SerialCollisionException($config->key, $field->getLabel(), $submitted);
            }

            try {
                $serial->value = $submitted;
                $serial->save();
            } catch (UniqueConstraintViolationException) {
                // Lost a race with a concurrent edit; same answer as above.
                throw new SerialCollisionException($config->key, $field->getLabel(), $submitted);
            }
        }

        return $values;
    }

    /**
     * Preview the values a new entry would receive, without reserving them.
     *
     * @return array<string, string>
     */
    public function preview(Space $space, Block $block, ?Content $parent, string $languageIso): array
    {
        $context = $this->context($block, $parent, $languageIso, []);
        $gapStrategy = $this->gapStrategy($space);
        $preview = [];

        foreach (SerialFieldConfig::collect($block->schema) as $key => ['config' => $config]) {
            $preview[$key] = $this->allocator->preview($config, $context, $gapStrategy);
        }

        return $preview;
    }

    /**
     * Release the reservations of a trashed entry when the space reuses gaps.
     */
    public function onTrashed(Space $space, Content $content): void
    {
        if ($this->gapStrategy($space) !== 'reuse') {
            return;
        }

        $this->allocator->release((string) $content->id);
    }

    /**
     * Re-reserve an entry's numbers after a restore.
     *
     * Under `preserve` the rows were never released and this is a no-op. Under
     * `reuse` the numbers may have been handed to someone else in the meantime,
     * in which case the entry is renumbered — the trade-off that gap reuse buys.
     *
     * @return array<string, string> values that changed, keyed by field
     */
    public function onRestored(Space $space, Content $content): array
    {
        if ($this->gapStrategy($space) !== 'reuse') {
            return [];
        }

        // Loaded explicitly rather than via the relation: content lifecycle
        // events re-load `block` with a restricted column set that omits the
        // schema, and a schema-less block reports no serial fields at all.
        $block = Block::query()->find($content->block_id);

        if (! $block) {
            return [];
        }

        $serialFields = SerialFieldConfig::collect($block->schema);

        if ($serialFields === []) {
            return [];
        }

        $parent = $content->parent_id
            ? Content::query()->with('current_version')->find($content->parent_id)
            : null;

        $values = $content->getCurrentContent();
        $context = $this->context($block, $parent, (string) $content->language_iso, $values, $content->created_at);
        $gapStrategy = $this->gapStrategy($space);
        $changed = [];

        foreach ($serialFields as $key => ['config' => $config, 'field' => $field]) {
            if ($this->hasLedgerRow((string) $content->id, $key)) {
                continue;
            }

            $original = $values[$key] ?? null;
            $scopeKey = $this->scopeKeys->scopeKey($config->scope, $context);
            $uniqueKey = $this->scopeKeys->uniqueKey($config->unique, $config->scope, $context);
            $number = $this->numberFor($original, $config, $context);

            // Prefer the original number so a restore is invisible whenever the
            // slot is still free.
            if ($number !== null && ! $this->numberTaken($scopeKey, $number)) {
                $this->allocator->claim(
                    (string) $content->id,
                    $key,
                    $scopeKey,
                    $uniqueKey,
                    $number,
                    (string) $original,
                );

                continue;
            }

            $allocation = $this->allocator->allocate($config, $field, $context, (string) $content->id, $gapStrategy);
            $values[$key] = $allocation->value;
            $changed[$key] = $allocation->value;
            $context = $context->withValues($values);
        }

        return $changed;
    }

    /**
     * Restore an entry's reservations and, when a number had to change, record
     * the new value as a version so the change is visible in the history rather
     * than silently rewriting the current one.
     */
    public function restoreFor(Space $space, Content $content): void
    {
        $changed = $this->onRestored($space, $content);

        if ($changed === []) {
            return;
        }

        $content->loadMissing('current_version');

        $version = ContentVersion::createWithContentContext([
            'message' => 'Serial reallocated after restore',
            'content_id' => $content->id,
            'parent_id' => $content->current_version_id,
            'content' => array_replace($content->getCurrentContent(), $changed),
            'created_by_id' => $content->current_version?->created_by_id,
        ], $content);

        $content->current_version_id = $version->id;
        $content->saveQuietly();
    }

    public function gapStrategy(Space $space): string
    {
        return $space->settings->getSerialGapStrategy();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function context(
        Block $block,
        ?Content $parent,
        string $languageIso,
        array $values = [],
        ?Carbon $createdAt = null,
    ): SerialContext {
        return new SerialContext(
            block: $block,
            parent: $parent,
            languageIso: $languageIso,
            values: $values,
            createdAt: $createdAt,
        );
    }

    /**
     * The canonical entry's serial values, when this entry is a translation.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function inheritedValues(Content $content, array $keys): array
    {
        if (! $content->i18n_parent_id) {
            return [];
        }

        $canonical = Content::query()
            ->with('current_version')
            ->find($content->i18n_parent_id);

        if (! $canonical) {
            return [];
        }

        $canonicalValues = $canonical->getCurrentContent();
        $inherited = [];

        foreach ($keys as $key) {
            if ($this->filled($canonicalValues[$key] ?? null)) {
                $inherited[$key] = $canonicalValues[$key];
            }
        }

        return $inherited;
    }

    /**
     * Reserve a value that already exists rather than generating one.
     *
     * The number it is filed under is recovered from the value when the format
     * can explain it, so an adopted `1-004` keeps slot 4 instead of pushing the
     * counter past every backfilled entry.
     */
    protected function claimExistingValue(
        SerialFieldConfig $config,
        SchemaField $field,
        SerialContext $context,
        string $contentId,
        string $value,
    ): void {
        $scopeKey = $this->scopeKeys->scopeKey($config->scope, $context);
        $uniqueKey = $this->scopeKeys->uniqueKey($config->unique, $config->scope, $context);

        // Checked up front so a duplicate override surfaces as a validation
        // error on the field rather than as a constraint violation.
        if ($uniqueKey !== null && $this->valueTaken($uniqueKey, $value, $contentId)) {
            throw new SerialCollisionException($config->key, $field->getLabel(), $value);
        }

        $number = $this->numberFor($value, $config, $context);

        if ($number === null || $this->numberTaken($scopeKey, $number)) {
            $number = $this->allocator->peek($scopeKey, 'preserve');
        }

        $this->allocator->claim($contentId, $config->key, $scopeKey, $uniqueKey, $number, $value);
    }

    protected function valueTaken(string $uniqueKey, string $value, string $exceptContentId): bool
    {
        return ContentSerial::query()
            ->where('unique_key', $uniqueKey)
            ->where('value', $value)
            ->where('content_id', '!=', $exceptContentId)
            ->exists();
    }

    protected function hasLedgerRow(string $contentId, string $fieldKey): bool
    {
        return ContentSerial::query()
            ->where('content_id', $contentId)
            ->where('field_key', $fieldKey)
            ->exists();
    }

    /**
     * Recover the number behind an existing value by re-rendering candidates —
     * cheaper and more robust than parsing the format backwards.
     */
    protected function numberFor(mixed $value, SerialFieldConfig $config, SerialContext $context): ?int
    {
        if (! is_string($value) || $value === '' || ! preg_match_all('/\d+/', $value, $matches)) {
            return null;
        }

        // Any digit run in the value could be the counter — `1-001` contains
        // both the parent prefix and the number. Re-rendering each candidate
        // identifies the right one without parsing the format backwards.
        $candidates = [];

        foreach ($matches[0] as $digits) {
            $candidates[] = (int) $digits;
            $candidates[] = (int) ltrim($digits, '0');
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate > 0 && $this->renderer->render($config->format, $context->withNumber($candidate)) === $value) {
                return $candidate;
            }
        }

        return null;
    }

    protected function numberTaken(string $scopeKey, int $number): bool
    {
        return ContentSerial::query()
            ->where('scope_key', $scopeKey)
            ->where('number', $number)
            ->exists();
    }

    protected function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
