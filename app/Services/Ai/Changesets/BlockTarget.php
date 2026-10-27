<?php

namespace App\Services\Ai\Changesets;

use App\Actions\Block\CreateBlockVersion;
use App\Http\Requests\Block\CreateBlockRequest;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\User;
use App\Services\Ai\Run\ToolError;
use App\Services\Content\Schema\BlockSchema;
use App\Services\Content\Schema\BlockSchemaRequestValidator;
use App\Services\Content\Schema\SchemaField;
use App\Services\Content\Schema\SchemaNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * Block definitions. The document is `{name, description, icon, color, type,
 * schema, editor}`; paths are `name`, `description`, `icon`, `color` or
 * `schema.<field key>` (the whole field definition). Schema changes do not
 * migrate content, so removing a field, renaming its key, changing its type
 * and changing the block's slug or type are left to a person.
 */
class BlockTarget implements ChangeTarget
{
    public const ATTRIBUTES = ['name', 'description', 'icon', 'color'];

    private const REFUSAL = 'Schema changes do not migrate content, so this would orphan or break stored values. Leave it to a person in the block editor.';

    public function __construct(
        private readonly BlockSchemaRequestValidator $schemaValidator,
        private readonly SchemaNormalizer $normalizer,
        private readonly CreateBlockVersion $createVersion,
    ) {}

    public function viewAbility(): string
    {
        return 'blocks.view';
    }

    public function editAbility(): string
    {
        return 'blocks.manage';
    }

    /** By ID or slug. Blocks are not pinned to a version. */
    public function find(string $id, ?string $versionId = null): ?TargetSnapshot
    {
        $block = Block::query()->find($id) ?? Block::query()->where('slug', $id)->first();

        if (! $block) {
            return null;
        }

        return new TargetSnapshot($block, (string) $block->name, [
            'name' => $block->name,
            'description' => $block->description,
            'icon' => $block->icon,
            'color' => $block->color,
            'type' => $block->type,
            'schema' => $block->schema?->toArray() ?? [],
            'editor' => $block->editor ?? [],
        ]);
    }

    /**
     * Field definitions in the form the block stores them, so the review
     * compares like with like.
     *
     * @param  array<string, mixed>  $patches
     * @return array<string, mixed>
     */
    public function normalize(array $patches): array
    {
        foreach ($patches as $path => $value) {
            $key = $this->fieldKey((string) $path);
            if ($key !== null && \is_array($value) && \is_string($value['key'] ?? $key) && ($value['key'] ?? $key) === $key) {
                $patches[$path] = $this->definitions([$key => $value])[$key] ?? $value;
            }
        }

        return $patches;
    }

    public function validate(Space $space, TargetSnapshot $snapshot, array $patches): array
    {
        $current = $snapshot->document['schema'] ?? [];
        $document = $snapshot->document;
        $problems = [];

        foreach ($patches as $path => $value) {
            $path = (string) $path;
            $key = $this->fieldKey($path);
            $existing = $key !== null ? ($current[$key] ?? null) : null;

            if ($path === 'slug' || $path === 'type') {
                $problems[] = "{$path}: changing a block's {$path} is not possible here. ".self::REFUSAL;
            } elseif ($key === null && ! \in_array($path, self::ATTRIBUTES, true)) {
                $problems[] = "{$path}: use name, description, icon, color or schema.<field key>.";
            } elseif ($key !== null && $value === null) {
                $problems[] = $existing !== null
                    ? "{$path}: removing a field is not possible here. ".self::REFUSAL
                    : "{$path}: pass the field definition.";
            } elseif ($key !== null && (! \is_array($value) || array_is_list($value))) {
                $problems[] = "{$path}: pass the whole field definition as an object, with at least `type` and `name`.";
            } elseif ($key !== null && ($value['key'] ?? $key) !== $key) {
                $problems[] = "{$path}: renaming a field key is not possible here. ".self::REFUSAL;
            } elseif (\is_array($existing) && SchemaField::canonicalizeType(\is_string($value['type'] ?? null) ? $value['type'] : null) !== $existing['type']) {
                $problems[] = "{$path}: changing the type of the existing \"{$existing['type']}\" field is not possible here. ".self::REFUSAL;
            } else {
                $document = FieldPath::set($document, $path, $value);
            }
        }

        $attributes = Validator::make(
            Arr::only($document, self::ATTRIBUTES),
            Arr::only((new CreateBlockRequest)->rules(), self::ATTRIBUTES),
            (new CreateBlockRequest)->messages(),
        );
        foreach ($attributes->errors()->messages() as $path => $messages) {
            if (\array_key_exists($path, $patches)) {
                $problems[] = "{$path}: ".implode(' ', $messages);
            }
        }

        // Only problems of the proposed fields: an older field that no longer
        // passes would otherwise block every change to the block.
        $schema = $document['schema'] ?? [];
        $keys = array_filter(array_map(fn ($path) => $this->fieldKey((string) $path), array_keys($patches)));
        foreach ($this->schemaValidator->validate($schema, $this->layout($document['editor'] ?? [], array_keys($schema))) as $path => $messages) {
            if (array_any($keys, fn (string $key) => str_starts_with($path, "schema.{$key}."))) {
                $problems[] = "{$path}: ".implode(' ', $messages);
            }
        }

        if ($problems !== []) {
            throw new ToolError("The change is invalid:\n- ".implode("\n- ", array_values(array_unique($problems))));
        }

        $types = [];
        foreach (array_keys($patches) as $path) {
            $types[$path] = $this->fieldKey((string) $path) !== null ? 'block_field' : 'text';
        }

        return $types;
    }

    /** Like the block editor: version the block as it is, then save. */
    public function apply(Space $space, TargetSnapshot $current, array $document, User $actor, string $message): void
    {
        /** @var Block $block */
        $block = $current->model;
        $schema = $document['schema'] ?? [];

        $this->createVersion->execute($block, $message, $actor);

        $block->fill([
            ...Arr::only($document, self::ATTRIBUTES),
            'schema' => $this->definitions($schema),
            'editor' => $this->layout($document['editor'] ?? [], array_keys($schema)),
        ]);
        $block->save();
    }

    /**
     * Validate a proposed new block like the block create request.
     *
     * @param  array<string, mixed>  $data  name, slug, type, description, icon, color, schema
     * @return array<string, mixed> the block's attributes, with an editor layout
     *
     * @throws ToolError
     */
    public function validateNew(array $data): array
    {
        $schema = $this->definitions(\is_array($data['schema'] ?? null) ? $data['schema'] : []);
        $data = [...Arr::only($data, ['name', 'slug', 'type', ...self::ATTRIBUTES]), 'schema' => $schema];
        $editor = $this->layout([], array_keys($schema));

        $request = new CreateBlockRequest;
        $validator = Validator::make($data, Arr::only($request->rules(), array_keys($data)), $request->messages());
        $problems = array_map(fn (array $messages) => implode(' ', $messages), $validator->errors()->messages());

        foreach ($this->schemaValidator->validate($schema, $editor) as $path => $messages) {
            $problems[$path] = implode(' ', $messages);
        }

        if ($problems !== []) {
            throw new ToolError("The block is invalid:\n- ".implode("\n- ", array_map(fn ($path, $message) => "{$path}: {$message}", array_keys($problems), $problems)));
        }

        return [...$data, 'editor' => $editor];
    }

    public function create(array $attributes): Block
    {
        return Block::query()->create($attributes);
    }

    /** Field key of a `schema.<key>` path. */
    private function fieldKey(string $path): ?string
    {
        return preg_match('/^schema\.([^.]+)$/', $path, $match) ? $match[1] : null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    private function definitions(array $schema): array
    {
        return BlockSchema::fromArray($this->normalizer->normalizeSchema($schema))->toArray();
    }

    /**
     * The editor shows only fields placed on a page, so a new field goes on
     * the first page. An empty layout puts every field on one page.
     *
     * @param  array<int, mixed>  $editor
     * @param  list<string>  $keys
     * @return list<array{header: string, items: list<string>}>
     */
    private function layout(array $editor, array $keys): array
    {
        $pages = $this->normalizer->normalizeEditor(array_values($editor), $keys);
        $placed = array_merge(...array_column($pages, 'items'));
        $pages[0]['items'] = [...$pages[0]['items'], ...array_values(array_diff($keys, $placed))];

        return $pages;
    }
}
