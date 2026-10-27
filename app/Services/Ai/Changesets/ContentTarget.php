<?php

namespace App\Services\Ai\Changesets;

use App\Actions\Content\UpdateContent;
use App\Exceptions\ContentVersionConflictException;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\Run\ToolError;
use App\Services\Content\Schema\ContentSchemaValidator;
use App\Services\Content\Schema\SchemaField;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Content entries. The document is a version's content; paths name a root
 * field (`title`) or a field inside a nested block (`body.<block id>.text`).
 */
class ContentTarget implements ChangeTarget
{
    public function __construct(
        private readonly ContentSchemaValidator $validator,
        private readonly UpdateContent $update,
    ) {}

    public function viewAbility(): string
    {
        return 'content.view';
    }

    public function editAbility(): string
    {
        return 'content.manage';
    }

    public function find(string $id, ?string $versionId = null): ?TargetSnapshot
    {
        $content = Content::query()->with(['block', 'current_version'])->find($id);

        if (! $content) {
            return null;
        }

        $version = $versionId === null || $versionId === $content->current_version_id
            ? $content->current_version
            : ContentVersion::query()->where('content_id', $content->id)->find($versionId);

        if ($versionId !== null && ! $version) {
            return null;
        }

        return new TargetSnapshot($content, (string) $content->name, $version?->content ?? [], $version?->id);
    }

    public function validate(Space $space, TargetSnapshot $snapshot, array $patches): array
    {
        /** @var Content $content */
        $content = $snapshot->model;
        $block = $content->block ?? throw new ToolError('This entry has no block.');
        $document = $snapshot->document;
        $types = [];

        $problems = [];
        foreach ($patches as $path => $value) {
            $field = $this->fieldAt($block, $document, (string) $path);
            $types[$path] = $field['type'];
            if ($field['type'] === 'blocks') {
                $problems = [...$problems, ...$this->blockListProblems((string) $path, $field['field'], $value)];
            }
            $document = FieldPath::set($document, (string) $path, $value);
        }

        $result = $this->validator->validateSubmission(
            $space,
            $block,
            $document,
            $content,
            $content->language_iso,
            $content->i18n_parent_id,
            'save',
        );

        foreach ($patches as $path => $value) {
            $indexPath = 'content.'.FieldPath::toIndexPath($document, (string) $path);
            foreach ($result->errors as $key => $messages) {
                if ($key === $indexPath || str_starts_with($key, "{$indexPath}.")) {
                    $problems[] = "{$path}: ".implode(' ', (array) $messages);
                }
            }

            // Save validation prunes fields hidden by a condition or not
            // stored on a translation; such a patch would silently vanish.
            if ($value !== null && FieldPath::get($result->content, (string) $path) === null) {
                $problems[] = "{$path}: this field is hidden or not stored on this entry.";
            }
        }

        if ($problems !== []) {
            throw new ToolError("The patch is invalid:\n- ".implode("\n- ", $problems));
        }

        return $types;
    }

    public function apply(Space $space, TargetSnapshot $current, array $document, User $actor, string $message): void
    {
        /** @var Content $content */
        $content = $current->model;

        try {
            $this->update->execute([
                'content' => $document,
                'parent_version_id' => $current->versionId,
                'message' => $message,
                // Required fields left empty elsewhere in the draft are a
                // warning on save, not a reason to refuse the change.
                'force' => true,
            ], $content, $space, $actor);
        } catch (ContentVersionConflictException) {
            throw new ConflictHttpException("\"{$content->name}\" was saved while the changes were applied. Try again.");
        }
    }

    /**
     * The field a path ends at, with its canonical type. A path alternates
     * field keys and block item IDs and must end at a field: whole field
     * values are patched.
     *
     * @return array{type: string, field: array<string, mixed>}
     */
    public function fieldAt(Block $root, array $document, string $path): array
    {
        $schema = $root->schema?->toArray() ?? [];
        $node = $document;
        $segments = explode('.', $path);
        $last = \count($segments) - 1;

        for ($i = 0; $i <= $last; $i++) {
            $key = $segments[$i];
            $field = $schema[$key] ?? null;

            if (! \is_array($field)) {
                throw new ToolError("Unknown field \"{$key}\" in \"{$path}\". Use the field keys of the block schema.");
            }

            $type = SchemaField::canonicalizeType($field['type'] ?? null);
            if ($i === $last) {
                return ['type' => $type, 'field' => $field];
            }

            if ($type !== 'blocks' || $i + 1 === $last) {
                throw new ToolError("\"{$path}\" must end at a field: propose the whole value of \"{$key}\", or address a nested block as {$key}.<block id>.<field>.");
            }

            $itemId = $segments[++$i];
            $item = collect(\is_array($node[$key] ?? null) ? $node[$key] : [])->first(fn ($item) => \is_array($item) && ($item['id'] ?? null) === $itemId);
            $nested = \is_array($item) ? Block::query()->where('slug', (string) ($item['block'] ?? ''))->first() : null;

            if (! $nested) {
                throw new ToolError("No block with id \"{$itemId}\" in \"{$key}\". Use the block IDs from get_entry, never array positions.");
            }

            $schema = $nested->schema?->toArray() ?? [];
            $node = $item;
        }

        return ['type' => 'unknown', 'field' => []];
    }

    /**
     * Every item of a block list needs an ID, a block that exists, and one
     * the field allows. Save validation skips unknown blocks silently, and
     * the field's whitelist is otherwise only enforced by the editor.
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function blockListProblems(string $path, array $field, mixed $items): array
    {
        if (! \is_array($items)) {
            return [];
        }

        $allowed = ! empty($field['restrict_blocks']) ? array_values((array) ($field['block_whitelist'] ?? [])) : null;
        $slugs = array_values(array_unique(array_filter(array_map(fn ($item) => \is_array($item) ? ($item['block'] ?? null) : null, $items), 'is_string')));
        $known = Block::query()->whereIn('slug', $slugs)->pluck('slug')->all();

        $problems = [];
        foreach ($items as $item) {
            $slug = \is_array($item) ? ($item['block'] ?? null) : null;
            if (! \is_array($item) || ! \is_string($item['id'] ?? null) || $item['id'] === '' || ! \is_string($slug)) {
                $problems[] = "{$path}: every block needs an id and a block slug.";
            } elseif (! \in_array($slug, $known, true)) {
                $problems[] = "{$path}: there is no block \"{$slug}\". Use get_block_list to find the slugs.";
            } elseif ($allowed !== null && ! \in_array($slug, $allowed, true)) {
                $problems[] = "{$path}: \"{$slug}\" is not allowed here. Allowed: ".implode(', ', $allowed).'.';
            }
        }

        return array_values(array_unique($problems));
    }
}
