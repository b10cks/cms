<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Content\Serial\ContentSerialAssigner;
use App\Services\Content\Serial\SerialCollisionException;
use App\Services\Content\Serial\SerialFieldConfig;
use App\Support\SpaceContext;
use Illuminate\Console\Command;

/**
 * Backfill serials for entries that predate the field.
 *
 * Walks the tree parent-first and in creation order, so ancestor prefixes exist
 * before the children that reference them and the numbers people would have got
 * had the field been there from the start are the numbers they get now.
 */
class AssignContentSerialsCommand extends Command
{
    protected $signature = 'contents:assign-serials
        {space_id : The ID of the space}
        {block : Block slug or ID}
        {field? : Only backfill this field key}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Assign values to serial fields on existing content';

    public function __construct(protected ContentSerialAssigner $assigner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $space = Space::find($this->argument('space_id'));

        if (! $space) {
            $this->error(sprintf('Space %s not found.', $this->argument('space_id')));

            return self::FAILURE;
        }

        $restoreSpace = SpaceContext::enter($space);

        try {
            return $this->backfill($space);
        } finally {
            $restoreSpace();
        }
    }

    protected function backfill(Space $space): int
    {

        $blockRef = (string) $this->argument('block');
        $block = Block::query()
            ->where('id', $blockRef)
            ->orWhere('slug', $blockRef)
            ->first();

        if (! $block) {
            $this->error(sprintf('Block %s not found in this space.', $blockRef));

            return self::FAILURE;
        }

        $serialFields = SerialFieldConfig::collect($block->schema);
        $only = $this->argument('field');

        if ($only !== null) {
            $serialFields = array_intersect_key($serialFields, [$only => true]);
        }

        if ($serialFields === []) {
            $this->error('This block has no matching serial fields.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $entries = $this->orderedEntries($block);

        $this->info(sprintf(
            'Backfilling %s on %d entries%s.',
            implode(', ', array_keys($serialFields)),
            $entries->count(),
            $dryRun ? ' (dry run)' : '',
        ));

        $assigned = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($entries as $content) {
            // Translations inherit from the canonical entry, which the ordering
            // has already processed.
            $values = $content->getCurrentContent();
            $pending = array_filter(
                array_keys($serialFields),
                static fn (string $key): bool => ($values[$key] ?? '') === '' || $values[$key] === null,
            );

            if ($dryRun) {
                if ($pending === []) {
                    $skipped++;

                    continue;
                }

                $this->line(sprintf('  %s → would assign %s', $content->full_slug, implode(', ', $pending)));
                $assigned++;

                continue;
            }

            try {
                $updated = $this->assigner->assignOnCreate(
                    $space,
                    $block,
                    $content,
                    $content->parent_id ? Content::query()->with('current_version')->find($content->parent_id) : null,
                    $values,
                    // Values that are already there were assigned by an import
                    // or an earlier system; adopt them into the ledger instead
                    // of renumbering live identifiers.
                    adoptExistingValues: true,
                );
            } catch (SerialCollisionException $exception) {
                $this->warn(sprintf('  %s → %s', $content->full_slug, $exception->getMessage()));
                $failed++;

                continue;
            }

            if ($updated === $values) {
                $skipped++;

                continue;
            }

            $version = ContentVersion::createWithContentContext([
                'message' => 'Serial assigned',
                'content_id' => $content->id,
                'parent_id' => $content->current_version_id,
                'content' => $updated,
            ], $content);

            $content->current_version_id = $version->id;
            $content->saveQuietly();

            $this->line(sprintf(
                '  %s → %s',
                $content->full_slug,
                implode(', ', array_map(
                    static fn (string $key): string => $key.'='.($updated[$key] ?? ''),
                    $pending,
                )),
            ));
            $assigned++;
        }

        $this->info(sprintf('Assigned %d, skipped %d, failed %d.', $assigned, $skipped, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Parents before children, then oldest first: the order the entries would
     * have been created in.
     *
     * @return \Illuminate\Support\Collection<int, Content>
     */
    protected function orderedEntries(Block $block): \Illuminate\Support\Collection
    {
        $entries = Content::query()
            ->with('current_version')
            ->where('block_id', $block->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $depth = [];

        $resolveDepth = function (Content $content) use (&$depth, &$resolveDepth): int {
            if (isset($depth[$content->id])) {
                return $depth[$content->id];
            }

            $level = 0;
            $parentId = $content->parent_id;
            $guard = 0;

            while ($parentId !== null && $guard++ < 64) {
                $level++;
                $parentId = Content::query()->whereKey($parentId)->value('parent_id');
            }

            return $depth[$content->id] = $level;
        };

        return $entries
            ->sortBy([
                fn (Content $a, Content $b): int => $resolveDepth($a) <=> $resolveDepth($b),
                fn (Content $a, Content $b): int => ($a->i18n_parent_id ? 1 : 0) <=> ($b->i18n_parent_id ? 1 : 0),
                fn (Content $a, Content $b): int => (string) $a->created_at <=> (string) $b->created_at,
            ])
            ->values();
    }

}
