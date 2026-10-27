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

        foreach ($patches as $path => $value) {
            $types[$path] = $this->fieldType($block, $document, (string) $path);
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

        $problems = [];
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
     * Type of the field a path ends at. A path alternates field keys and
     * block item IDs and must end at a field: whole field values are patched.
     */
    private function fieldType(Block $root, array $document, string $path): string
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
                return $type;
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

        return 'unknown';
    }
}
