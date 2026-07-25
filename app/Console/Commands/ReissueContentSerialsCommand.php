<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentSerial;
use App\Models\Space\ContentVersion;
use App\Services\Content\Serial\ContentSerialAssigner;
use App\Services\Content\Serial\ScopeKeyBuilder;
use App\Services\Content\Serial\SerialFieldConfig;
use App\Services\Content\Serial\TemplateRenderer;
use App\Support\SpaceContext;
use Illuminate\Console\Command;

/**
 * Re-render existing serials against the block's current format.
 *
 * Changing a format never rewrites values that are already out in the world —
 * an identifier that silently changes is not an identifier. This command is the
 * explicit opt-in for teams that want consistency instead. Numbers are kept:
 * only the rendering changes, so `1-001` can become `H-1-0001` but never `2`.
 */
class ReissueContentSerialsCommand extends Command
{
    protected $signature = 'contents:reissue-serials
        {space_id : The ID of the space}
        {block : Block slug or ID}
        {field : The serial field key}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Re-render serial values with the current format, keeping their numbers';

    public function __construct(
        protected ContentSerialAssigner $assigner,
        protected TemplateRenderer $renderer,
        protected ScopeKeyBuilder $scopeKeys,
    ) {
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
            return $this->reissue($space);
        } finally {
            $restoreSpace();
        }
    }

    protected function reissue(Space $space): int
    {

        $blockRef = (string) $this->argument('block');
        $block = Block::query()->where('id', $blockRef)->orWhere('slug', $blockRef)->first();

        if (! $block) {
            $this->error(sprintf('Block %s not found in this space.', $blockRef));

            return self::FAILURE;
        }

        $key = (string) $this->argument('field');
        $serialFields = SerialFieldConfig::collect($block->schema);

        if (! isset($serialFields[$key])) {
            $this->error(sprintf('%s is not a serial field on this block.', $key));

            return self::FAILURE;
        }

        /** @var SerialFieldConfig $config */
        $config = $serialFields[$key]['config'];
        $dryRun = (bool) $this->option('dry-run');

        $serials = ContentSerial::query()
            ->where('field_key', $key)
            ->orderBy('scope_key')
            ->orderBy('number')
            ->get();

        $changed = 0;
        $unchanged = 0;

        foreach ($serials as $serial) {
            $content = Content::query()
                ->with(['current_version', 'block'])
                ->whereNull('deleted_at')
                ->find($serial->content_id);

            if (! $content || $content->block_id !== $block->id) {
                continue;
            }

            $parent = $content->parent_id
                ? Content::query()->with('current_version')->find($content->parent_id)
                : null;

            $values = $content->getCurrentContent();
            $context = $this->assigner
                ->context($block, $parent, (string) $content->language_iso, $values, $content->created_at)
                ->withNumber($serial->number);

            $rendered = $this->renderer->render($config->format, $context);

            if ($rendered === $serial->value) {
                $unchanged++;

                continue;
            }

            $this->line(sprintf('  %s → %s becomes %s', $content->full_slug, $serial->value, $rendered));
            $changed++;

            if ($dryRun) {
                continue;
            }

            $serial->value = $rendered;
            $serial->unique_key = $this->scopeKeys->uniqueKey($config->unique, $config->scope, $context);
            $serial->save();

            $version = ContentVersion::createWithContentContext([
                'message' => 'Serial re-issued',
                'content_id' => $content->id,
                'parent_id' => $content->current_version_id,
                'content' => array_replace($values, [$key => $rendered]),
                'created_by_id' => $content->current_version?->created_by_id,
            ], $content);

            $content->current_version_id = $version->id;
            $content->saveQuietly();
        }

        $this->info(sprintf(
            '%d re-issued, %d already current%s.',
            $changed,
            $unchanged,
            $dryRun ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }
}
