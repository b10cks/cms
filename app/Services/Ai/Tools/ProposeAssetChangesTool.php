<?php

namespace App\Services\Ai\Tools;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperationItem;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Services\Ai\Assets\AssetFieldRules;
use App\Services\Ai\Assets\AssetPlan;
use App\Services\Ai\Assets\AssetReview;
use App\Services\Ai\Assets\InspectionImages;
use App\Services\Ai\Changesets\CanonicalJson;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Run\Transcript;
use Illuminate\Support\Str;

/**
 * Proposes metadata, tag and folder changes for assets, checked against the
 * run's approved plan and the field rules of the folder each asset ends up
 * in. Without approval in the plan it only fills empty fields and adds tags.
 * A proposal that moves an asset is one bundle: its items are accepted
 * together. The latest proposal for an asset replaces this run's earlier
 * pending ones. Nothing is saved until an editor accepts.
 *
 * A change rests on the asset's image unless it declares another basis, and
 * then needs the image to have reached the model ({@see InspectionImages}).
 * Nonvisual changes declare the existing metadata they derive from or quote
 * the user's instruction; both are checked and recorded for review.
 *
 * When the approved plan names outputs, the batch entry owes parts and each
 * field, the tags and the placement count on their own. Valid changes go ahead
 * while invalid or unrequested ones stay owed, a proposal never replaces the
 * other pending parts, and parts proposed by an earlier batch stay as they are.
 */
class ProposeAssetChangesTool extends AssetProposalTool implements ReportsOutcomes
{
    public const NAME = 'propose_asset_changes';

    public const MAX_ASSETS = 20;

    private const MAX_TEXT = 300;

    /** Short enough for a real sentence fragment, long enough that "yes" or "the" authorizes nothing. */
    private const MIN_QUOTE = 8;

    /** @var list<array{target_type: string, target_id: string, outcome: string, reason: string|null}> */
    private array $outcomes = [];

    /** @var array<string, array{message: int, sha256: string|null}> */
    private array $delivered = [];

    /** @var array<string, array{owed: list<string>, invalid: array<string, string>, kept: list<string>}> what each asset owing parts still owes, per call */
    private array $reports = [];

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Proposes changes for up to '.self::MAX_ASSETS.' assets you inspected; nothing is saved until an editor accepts. Per asset: '
            .'`fields` {<language>: {<field>: text}} with the language keys and AI fields inspect_assets shows (`_default` is the default language); '
            .'`add_tags` with tag IDs or new:<ID> of proposed tags; `remove_tags`; `folder` as a folder ID, new:<ID> of a proposed folder, or "root". '
            .'Each asset needs a `group` (a short label shared by assets that get the same kind of change, for review) and a `reason`. '
            .'Changes rest on the image you have seen, so propose in a later turn than the inspection. Only for changes that need no image set `basis`: '
            .'"metadata" with `sources` (["<language>.<field>"] holding the text you derive from, for example to translate it), or "instruction" with `instruction` quoting the user\'s words verbatim. '
            .'Set `uncertain` with what a person should check when you cannot tell from the image; such changes are reviewed one by one. '
            .'Fields that already have a value, tag removals and moves are refused unless the plan allows them. '
            .'Pass all changes of an asset in one entry: a later entry for the same asset replaces the earlier one. '
            .'List assets that need nothing under `unchanged` with a reason. Refused assets come back under `errors` to fix. '
            .'When a batch entry has `owed` parts, only those count: `outputs` in the result says what each asset still owes and which parts were refused. '
            .'Banned wording or a changed protected name comes back on the asset under `glossary_findings`, with the field and the wording to write. Read `glossary_note` in the result.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assets' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_ASSETS,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'group' => ['type' => 'string', 'description' => 'Short review group label, the same for assets with the same kind of change.'],
                            'reason' => ['type' => 'string', 'description' => 'What in the image supports the change.'],
                            'fields' => ['type' => 'object', 'description' => '{<language>: {<field>: text}}'],
                            'add_tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'remove_tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'folder' => ['type' => 'string', 'description' => 'Folder ID, new:<proposal ID> or "root".'],
                            'uncertain' => ['type' => 'string', 'description' => 'What a person should check before accepting.'],
                            'basis' => ['type' => 'string', 'enum' => [AssetReview::BASIS_IMAGE, AssetReview::BASIS_METADATA, AssetReview::BASIS_INSTRUCTION], 'description' => 'What the change rests on. Defaults to image.'],
                            'sources' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'With basis metadata: the <language>.<field> values the change derives from.'],
                            'instruction' => ['type' => 'string', 'description' => 'With basis instruction: the user\'s words that ask for this change, verbatim.'],
                        ],
                        'required' => ['id', 'group', 'reason'],
                    ],
                ],
                'unchanged' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'string'], 'reason' => ['type' => 'string']],
                        'required' => ['id', 'reason'],
                    ],
                ],
            ],
        ];
    }

    public function takeOutcomes(): array
    {
        [$outcomes, $this->outcomes] = [$this->outcomes, []];

        return $outcomes;
    }

    public function execute(array $input): mixed
    {
        $this->outcomes = [];
        $this->reports = [];
        $this->delivered = InspectionImages::delivered($this->run->transcript ?? []);
        $this->target(AiChangesetItem::TARGET_ASSET);
        $entries = array_values(\is_array($input['assets'] ?? null) ? $input['assets'] : []);
        $unchanged = array_values(\is_array($input['unchanged'] ?? null) ? $input['unchanged'] : []);

        if ($entries === [] && $unchanged === []) {
            throw new ToolError('Pass assets to change or assets that stay unchanged.');
        }
        if (\count($entries) > self::MAX_ASSETS) {
            throw new ToolError('Pass at most '.self::MAX_ASSETS.' assets per call.');
        }

        $proposed = [];
        $recorded = [];
        $kept = [];
        $errors = [];

        foreach ($entries as $entry) {
            $id = \is_array($entry) ? (string) ($entry['id'] ?? '') : '';

            try {
                $items = $this->propose($id, \is_array($entry) ? $entry : []);
                $owing = isset($this->reports[$id]);
                if ($items !== []) {
                    array_push($recorded, ...$items);
                    $proposed[] = ['id' => $id, 'item_ids' => array_map(fn (AiChangesetItem $item) => $item->id, $items), ...$this->fieldFindings($items)];
                    if (! $owing) {
                        $this->outcome($id, ReportsOutcomes::PROPOSED, null);
                    }
                } elseif (! $owing) {
                    $kept[] = $id;
                    $this->outcome($id, ReportsOutcomes::UNCHANGED, 'no_change');
                }
            } catch (ToolError $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        foreach ($unchanged as $entry) {
            $id = \is_array($entry) ? (string) ($entry['id'] ?? '') : '';

            try {
                $this->ensureInScope(AiChangesetItem::TARGET_ASSET, $id);
                $this->outcome($id, ReportsOutcomes::UNCHANGED, Str::limit(trim((string) ($entry['reason'] ?? '')), self::MAX_TEXT, '') ?: null);
                $owing = $this->operationContext()?->owing(AiChangesetItem::TARGET_ASSET, $id);
                // Saying nothing is needed keeps what may stay; empty parts stay owed.
                $open = $owing !== null ? array_values(array_diff($owing->openParts(null), (array) ($owing->detail['overwritable'] ?? []))) : [];
                if ($open !== []) {
                    $errors[] = ['id' => $id, 'error' => 'This asset still owes '.implode(', ', $open).'. Propose them, or report each with report_items `outputs`.'];
                } else {
                    $kept[] = $id;
                }
            } catch (ToolError $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        // Each asset already names its own flagged fields; what remains is what to do about them.
        $glossary = array_diff_key($this->findingsResult($recorded, written: false), ['glossary_findings' => true]);

        return ['proposed' => $proposed, 'unchanged' => $kept, 'errors' => $errors, ...($this->reports !== [] ? ['outputs' => $this->reports] : []), ...$glossary];
    }

    /**
     * What this call's proposal for one asset breaks, by field.
     *
     * @param  list<AiChangesetItem>  $items
     * @return array{glossary_findings?: list<array<string, string|null>>}
     */
    private function fieldFindings(array $items): array
    {
        $findings = [];
        foreach ($items as $item) {
            foreach ($item->glossary_findings ?? [] as $finding) {
                $findings[] = ['path' => $item->path, ...$finding];
            }
        }

        return $findings === [] ? [] : ['glossary_findings' => $findings];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<AiChangesetItem>
     *
     * @throws ToolError
     */
    private function propose(string $id, array $entry): array
    {
        $asset = $id !== '' ? Asset::query()->with('folder')->find($id) : null;
        if (! $asset) {
            throw new ToolError('No asset with this ID.');
        }
        $this->ensureInScope(AiChangesetItem::TARGET_ASSET, $id);
        $owing = $this->operationContext()?->owing(AiChangesetItem::TARGET_ASSET, $id);
        $invalid = [];
        $kept = [];

        $group = Str::limit(trim((string) ($entry['group'] ?? '')), 80, '');
        $reason = Str::limit(trim((string) ($entry['reason'] ?? '')), self::MAX_TEXT, '');
        if ($group === '' || $reason === '') {
            throw new ToolError('Every asset needs a group and a reason.');
        }

        $plan = $this->plan();
        $current = \is_array(data_get($asset->data, 'fields')) ? $asset->data['fields'] : [];
        $labels = [];

        $destination = ['ref' => $asset->folder_id, 'rules' => $asset->folder, 'depends_on' => null];
        $moves = \array_key_exists('folder', $entry) && ($entry['folder'] === 'root' ? null : $entry['folder']) !== $asset->folder_id;
        if ($moves && $owing?->settledEarlier(AssetPlan::PART_FOLDER)) {
            $moves = false;
            $kept[] = AssetPlan::PART_FOLDER;
        }
        if ($moves) {
            if (! $plan->move) {
                throw new ToolError('The approved plan does not allow moving assets. Leave the folder as it is.');
            }
            $destination = $this->folderRef($entry['folder']);
            $labels[$destination['ref'] ?? 'root'] = $destination['label'];
            if ($asset->folder_id !== null) {
                $labels[$asset->folder_id] = $this->structure->path($asset->folder_id);
            }
        }

        $fields = \is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
        $patches = $owing !== null
            ? $this->owedPatches($owing, $destination['rules'], $current, $fields, $invalid, $kept)
            : $this->fieldPatches($current, $fields);
        $problems = $this->rules->problems($this->space, $destination['rules'], $patches);
        if ($problems !== []) {
            throw new ToolError(implode(' ', $problems));
        }

        [$add, $remove, $tagDeps] = $this->tagChanges($asset, $entry, $labels);
        if ($owing !== null && ($add !== [] || $remove !== [])) {
            $tagProblem = match (true) {
                ! \in_array(AssetPlan::PART_TAGS, $owing->detail['expected_parts'], true) => 'The approved plan does not request tags.',
                $owing->settledEarlier(AssetPlan::PART_TAGS) => '',
                default => null,
            };
            if ($tagProblem !== null) {
                [$add, $remove, $tagDeps] = [[], [], []];
                $tagProblem === '' ? $kept[] = AssetPlan::PART_TAGS : $invalid[AssetPlan::PART_TAGS] = $tagProblem;
            }
        }

        $flags = [];
        if (\is_string($entry['uncertain'] ?? null) && trim($entry['uncertain']) !== '') {
            $flags[] = ['code' => AssetReview::FLAG_UNCERTAIN, 'message' => Str::limit(trim($entry['uncertain']), self::MAX_TEXT, '')];
        }
        $merged = array_replace_recursive($current, $patches);
        $missing = $moves ? $this->rules->missingRequired($this->space, $destination['rules'], $merged) : [];
        if ($missing !== []) {
            $flags[] = ['code' => AssetReview::FLAG_MISSING_REQUIRED, 'message' => 'The destination folder requires '.implode(', ', $missing).', which stay empty.'];
        }

        $changes = $patches !== [] || $add !== [] || $remove !== [] || $moves;

        $review = array_filter([
            'group' => $group,
            'reason' => $reason,
            'flags' => $flags,
            'bundle' => $moves ? strtolower((string) Str::ulid()) : null,
            'labels' => $labels,
            'evidence' => $changes ? $this->evidence($asset, $entry, $current, $patches) : null,
        ], fn ($value) => $value !== null && $value !== []);

        $items = [];
        foreach ($patches as $language => $values) {
            foreach ($values as $field => $text) {
                $items[] = $this->item($asset, "fields.{$language}.{$field}", 'textarea', $current[$language][$field] ?? null, $text, $review);
            }
        }
        if ($add !== [] || $remove !== []) {
            $items[] = $this->item($asset, 'tags', 'asset_tags', array_values($asset->tags ?? []), ['add' => $add, 'remove' => $remove], [...$review, ...($tagDeps !== [] ? ['depends_on' => $tagDeps] : [])]);
        }
        if ($moves) {
            $items[] = $this->item($asset, 'folder_id', 'asset_folder', $asset->folder_id, $destination['ref'], [...$review, ...($destination['depends_on'] ? ['depends_on' => [$destination['depends_on']]] : [])]);
        }

        if ($owing === null) {
            $this->discardPending($id);

            return $items === [] ? [] : $this->record($items);
        }

        $created = $items === [] ? [] : $this->record($items);
        $this->reportParts($owing, $patches, $add !== [] || $remove !== [], $moves, $invalid, $kept);

        return $created;
    }

    /**
     * Report what this call did per part of the entry.
     *
     * @param  array<string, array<string, string>>  $patches
     * @param  array<string, string>  $invalid
     * @param  list<string>  $kept
     */
    private function reportParts(AiOperationItem $owing, array $patches, bool $tags, bool $moves, array $invalid, array $kept): void
    {
        $proposed = [];
        foreach ($patches as $language => $values) {
            foreach (array_keys($values) as $field) {
                $proposed[] = "{$language}.{$field}";
            }
        }
        if ($tags) {
            $proposed[] = AssetPlan::PART_TAGS;
        }
        if ($moves) {
            $proposed[] = AssetPlan::PART_FOLDER;
        }

        foreach ($proposed as $part) {
            $this->outcome($owing->target_id, ReportsOutcomes::PROPOSED, null, $part);
        }
        foreach ($kept as $part) {
            if (! $owing->settledEarlier($part)) {
                $this->outcome($owing->target_id, ReportsOutcomes::SATISFIED, 'has_value', $part);
            }
        }
        foreach ($invalid as $part => $message) {
            $this->outcome($owing->target_id, ReportsOutcomes::INVALID, 'invalid', (string) $part, $message);
        }

        $this->reports[$owing->target_id] = [
            'owed' => array_values(array_diff($owing->openParts(null), $proposed, $kept)),
            'invalid' => $invalid,
            'kept' => $kept,
        ];
    }

    /**
     * Patches for an entry that owes parts. Each field stands alone: a problem
     * with one comes back for that part and the valid ones go ahead.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $fields
     * @param  array<string, string>  $invalid
     * @param  list<string>  $kept
     * @return array<string, array<string, string>>
     */
    private function owedPatches(AiOperationItem $owing, AssetFolder|string|null $folder, array $current, array $fields, array &$invalid, array &$kept): array
    {
        $patches = [];

        foreach ($fields as $language => $values) {
            foreach (\is_array($values) ? $values : [] as $field => $value) {
                $part = "{$language}.{$field}";
                $single = [(string) $language => [(string) $field => $value]];

                if ($owing->settledEarlier($part)) {
                    $kept[] = $part;

                    continue;
                }

                try {
                    if (! \in_array($part, $owing->detail['expected_parts'], true)) {
                        throw new ToolError("{$part} is not an output the approved plan requests.");
                    }

                    $patch = $this->fieldPatches($current, $single);
                    $problems = $this->rules->problems($this->space, $folder, $patch);
                    if ($problems !== []) {
                        throw new ToolError(implode(' ', $problems));
                    }
                    if ($patch !== []) {
                        $patches[(string) $language][(string) $field] = $patch[$language][$field];
                    } elseif (\is_string($value) && trim($value) !== '') {
                        $kept[] = $part;
                    } else {
                        throw new ToolError("{$part} is empty. Report it as uninferable or intentionally_empty instead.");
                    }
                } catch (ToolError $e) {
                    $invalid[$part] = $e->getMessage();
                }
            }
        }

        return $patches;
    }

    private function operationContext(): ?OperationContext
    {
        return OperationContext::forRun($this->run);
    }

    /**
     * What the change rests on, checked against what the run can prove.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $current
     * @param  array<string, array<string, string>>  $patches
     * @return array<string, mixed>
     *
     * @throws ToolError
     */
    private function evidence(Asset $asset, array $entry, array $current, array $patches): array
    {
        return match ($entry['basis'] ?? AssetReview::BASIS_IMAGE) {
            AssetReview::BASIS_IMAGE => $this->imageEvidence($asset),
            AssetReview::BASIS_METADATA => $this->metadataEvidence($entry, $current, $patches),
            AssetReview::BASIS_INSTRUCTION => $this->instructionEvidence($entry),
            default => throw new ToolError('`basis` is image, metadata or instruction.'),
        };
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ToolError
     */
    private function imageEvidence(Asset $asset): array
    {
        $delivery = $this->delivered[$asset->id] ?? null;
        if ($delivery === null) {
            throw new ToolError('Its image has not reached you. Call inspect_assets and propose after you have seen the image. '
                .'If no image is needed, set basis to metadata or instruction.');
        }

        return ['basis' => AssetReview::BASIS_IMAGE, 'run_id' => $this->run->id, ...$delivery];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $current
     * @param  array<string, array<string, string>>  $patches
     * @return array<string, mixed>
     *
     * @throws ToolError
     */
    private function metadataEvidence(array $entry, array $current, array $patches): array
    {
        $sources = array_values(array_unique(array_filter(\is_array($entry['sources'] ?? null) ? $entry['sources'] : [], 'is_string')));

        foreach ($sources as $source) {
            [$language, $field] = array_pad(explode('.', $source, 2), 2, '');

            if (isset($patches[$language][$field]) || ! \is_string($current[$language][$field] ?? null) || AssetFieldRules::isEmpty($current[$language][$field])) {
                throw new ToolError("{$source} holds no existing text to derive from.");
            }
        }

        if ($sources === []) {
            throw new ToolError('Basis metadata needs `sources`: the <language>.<field> values you derive from. Without them inspect the image.');
        }

        return ['basis' => AssetReview::BASIS_METADATA, 'sources' => $sources];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     *
     * @throws ToolError
     */
    private function instructionEvidence(array $entry): array
    {
        $quote = \is_string($entry['instruction'] ?? null) ? Str::squish($entry['instruction']) : '';

        if (mb_strlen($quote) < self::MIN_QUOTE || ! Str::contains($this->userWords(), $quote, ignoreCase: true)) {
            throw new ToolError('Basis instruction needs `instruction` to quote the words of the user that ask for this change, verbatim. Without them inspect the image.');
        }

        return ['basis' => AssetReview::BASIS_INSTRUCTION, 'quote' => Str::limit($quote, self::MAX_TEXT, '')];
    }

    /**
     * The user's own requests and answers. Context and documents follow the
     * date line of a request and are data, so they authorize nothing; neither
     * do the labels of inspected images.
     */
    private function userWords(): string
    {
        $messages = $this->run->transcript ?? [];
        $words = array_filter(array_column(Transcript::fromArray($messages)->answers(), 'answer'), 'is_string');

        foreach ($messages as $message) {
            if ($message['role'] === 'user' && \is_string($message['content'] ?? null)) {
                $words[] = Str::before($message['content'], "\n\nToday is ");
            }
        }

        return Str::squish(implode("\n", $words));
    }

    /**
     * Proposed values that differ from the current ones. A field that holds a
     * value is only replaced, or cleared, when the plan allows overwriting it.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $fields
     * @return array<string, array<string, string>>
     *
     * @throws ToolError
     */
    private function fieldPatches(array $current, array $fields): array
    {
        $plan = $this->plan();
        $patches = [];

        foreach ($fields as $language => $values) {
            foreach (\is_array($values) ? $values : [] as $field => $value) {
                if (! \is_string($value)) {
                    throw new ToolError("{$language}.{$field}: must be text.");
                }
                if (! $plan->writesLanguage((string) $language)) {
                    throw new ToolError('The approved plan writes only these languages: '.implode(', ', $plan->languages ?? []).'.');
                }

                $now = $current[$language][$field] ?? null;
                $text = trim($value);
                if (CanonicalJson::equals($now, $text) || (AssetFieldRules::isEmpty($now) && $text === '')) {
                    continue;
                }
                if (! AssetFieldRules::isEmpty($now) && ! $plan->overwrites((string) $field)) {
                    throw new ToolError("{$language}.{$field} already has a value and the approved plan does not allow overwriting {$field}. Leave it as it is.");
                }

                $patches[(string) $language][(string) $field] = $text;
            }
        }

        return $patches;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, string>  $labels
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} tags to add, to remove, and the proposals they need
     *
     * @throws ToolError
     */
    private function tagChanges(Asset $asset, array $entry, array &$labels): array
    {
        $current = array_values(array_filter((array) ($asset->tags ?? []), 'is_string'));
        $add = [];
        $deps = [];

        foreach (\is_array($entry['add_tags'] ?? null) ? $entry['add_tags'] : [] as $ref) {
            $tag = $this->tagRef($ref);
            if (! \in_array($tag['ref'], $current, true) && ! \in_array($tag['ref'], $add, true)) {
                $add[] = $tag['ref'];
                $labels[$tag['ref']] = $tag['label'];
                if ($tag['depends_on'] !== null) {
                    $deps[] = $tag['depends_on'];
                }
            }
        }

        $remove = array_values(array_intersect(array_filter(\is_array($entry['remove_tags'] ?? null) ? $entry['remove_tags'] : [], 'is_string'), $current));
        if ($remove !== [] && ! $this->plan()->removeTags) {
            throw new ToolError('The approved plan does not allow removing tags. Only add tags.');
        }
        foreach ($remove as $id) {
            $labels[$id] = (string) ($this->structure->tag($id)?->name ?? $id);
        }

        return [$add, $remove, $deps];
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private function item(Asset $asset, string $path, string $type, mixed $before, mixed $after, array $review): array
    {
        return [
            'operation' => AiChangesetItem::OPERATION_UPDATE,
            'target_type' => AiChangesetItem::TARGET_ASSET,
            'target_id' => $asset->id,
            'target_label' => (string) $asset->filename,
            'path' => $path,
            'field_type' => $type,
            'before' => $before,
            'after' => $after,
            'review' => $review,
        ];
    }

    /** The latest proposal for an asset replaces this run's earlier pending ones. */
    private function discardPending(string $assetId): void
    {
        $changeset = AiChangeset::query()->ofRun($this->run)->first();
        if (! $changeset) {
            return;
        }

        $deleted = $changeset->items()
            ->where('status', AiChangesetItemStatus::Pending->value)
            ->where('target_type', AiChangesetItem::TARGET_ASSET)
            ->where('target_id', $assetId)
            ->delete();

        if ($deleted > 0) {
            $changeset->refreshCounts();
        }
    }

    private function outcome(string $id, string $outcome, ?string $reason, ?string $part = null, ?string $note = null): void
    {
        $this->outcomes[] = array_filter(['target_type' => AiChangesetItem::TARGET_ASSET, 'target_id' => $id, 'outcome' => $outcome, 'reason' => $reason, 'part' => $part, 'note' => $note], fn ($value) => $value !== null)
            + ['reason' => null];
    }
}
