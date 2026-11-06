<?php

namespace App\Services\Ai\Metadata;

use App\Models\Space\Block;
use App\Services\Content\Schema\SchemaField;

/**
 * The `meta` fields of a content document at any depth, walked against the
 * block schemas. A nested field keeps the stable path changesets use,
 * `body.<block id>.seo`, and a `ref` a plan can name without knowing the
 * document: `seo` for a root field, `<block slug>.seo` for a nested one.
 *
 * A block item without an ID has no stable path. Such a field is returned with
 * `addressable: false` and a position label, so a plan reports it as a
 * limitation instead of leaving it out of its coverage.
 */
final class MetadataFields
{
    /** @var array<string, Block|null> */
    private array $blocks = [];

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $schema  the entry block's schema
     * @return list<array{path: string, ref: string, translatable: bool, addressable: bool}>
     */
    public function of(array $document, array $schema): array
    {
        return $this->walk($document, $schema, '', null, true);
    }

    /**
     * The fields a metadata plan covers. Empty `fields` covers all of them.
     *
     * @param  array<string, mixed>  $plan  `plan.metadata`
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $schema
     * @return list<array{path: string, ref: string, translatable: bool, addressable: bool}>
     */
    public function planned(array $plan, array $document, array $schema): array
    {
        $fields = $this->of($document, $schema);

        return ($plan['fields'] ?? []) === []
            ? $fields
            : array_values(array_filter($fields, fn (array $field) => \in_array($field['ref'], $plan['fields'], true)));
    }

    /**
     * What a plan can name: root meta fields of the given entry blocks and the
     * meta fields of every block that can nest.
     *
     * @param  list<string>  $slugs  the entry blocks of the scope
     * @return list<string>
     */
    public static function refs(array $slugs): array
    {
        $refs = [];
        foreach (Block::query()->get(['slug', 'type', 'schema']) as $block) {
            $root = \in_array($block->slug, $slugs, true);
            foreach (MetadataTriage::metaFields($block->schema?->toArray() ?? []) as $field) {
                if ($root) {
                    $refs[$field] = true;
                }
                $refs["{$block->slug}.{$field}"] = true;
            }
        }

        return array_map('strval', array_keys($refs));
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $schema
     * @return list<array{path: string, ref: string, translatable: bool, addressable: bool}>
     */
    private function walk(array $document, array $schema, string $prefix, ?string $slug, bool $addressable): array
    {
        $fields = [];

        foreach ($schema as $key => $definition) {
            if (! \is_array($definition)) {
                continue;
            }

            $type = SchemaField::canonicalizeType($definition['type'] ?? null);
            if ($type === 'meta') {
                $fields[] = [
                    'path' => "{$prefix}{$key}",
                    'ref' => $slug !== null ? "{$slug}.{$key}" : (string) $key,
                    'translatable' => (bool) ($definition['translatable'] ?? false),
                    'addressable' => $addressable,
                ];
            } elseif ($type === 'blocks') {
                foreach (\is_array($document[$key] ?? null) ? array_values($document[$key]) : [] as $index => $item) {
                    $block = \is_array($item) ? $this->block((string) ($item['block'] ?? '')) : null;
                    if ($block === null) {
                        continue;
                    }

                    $id = \is_string($item['id'] ?? null) && $item['id'] !== '' ? $item['id'] : null;
                    $fields = [...$fields, ...$this->walk($item, $block->schema?->toArray() ?? [], "{$prefix}{$key}.".($id ?? "@{$index}").'.', $block->slug, $addressable && $id !== null)];
                }
            }
        }

        return $fields;
    }

    private function block(string $slug): ?Block
    {
        if ($slug === '') {
            return null;
        }

        return $this->blocks[$slug] ??= Block::query()->where('slug', $slug)->first();
    }
}
