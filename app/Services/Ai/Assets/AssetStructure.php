<?php

namespace App\Services\Ai\Assets;

use App\Models\Space\AssetFolder;
use App\Models\Space\AssetTag;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lookups over the space's folder tree and tag taxonomy, loaded once per
 * instance: folder paths, siblings and tags matched by name the way the
 * folder drop path compares them (NFC, case-insensitive).
 */
class AssetStructure
{
    /** @var array<string, AssetFolder>|null */
    private ?array $folders = null;

    /** @var array<string, AssetTag>|null */
    private ?array $tags = null;

    /**
     * Narrow a tag query to names containing the text, literally: tag names
     * may hold `%` and `_`. An explicit ESCAPE works on MySQL and SQLite alike.
     *
     * @param  Builder<AssetTag>  $query
     */
    public static function orWhereTagNameContains(Builder $query, string $text): void
    {
        $query->orWhereRaw("name LIKE ? ESCAPE '!'", ['%'.strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']).'%']);
    }

    /** @return array<string, AssetFolder> */
    public function folders(): array
    {
        return $this->folders ??= AssetFolder::query()->get(['id', 'name', 'parent_id', 'settings'])->keyBy('id')->all();
    }

    public function folder(?string $id): ?AssetFolder
    {
        return $id === null ? null : ($this->folders()[$id] ?? null);
    }

    /** "Brand / Logos"; empty for the library root. */
    public function path(?string $id): string
    {
        $names = [];
        $seen = [];

        while ($id !== null && ($folder = $this->folder($id)) && ! isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($names, (string) $folder->name);
            $id = $folder->parent_id;
        }

        return implode(' / ', $names);
    }

    /** IDs of the folder and every folder below it. */
    public function descendantIds(string $id): array
    {
        $children = [];
        foreach ($this->folders() as $folder) {
            $children[$folder->parent_id ?? ''][] = $folder->id;
        }

        $ids = [];
        $queue = [$id];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($ids[$current])) {
                continue;
            }
            $ids[$current] = true;
            array_push($queue, ...($children[$current] ?? []));
        }

        return array_keys($ids);
    }

    public function sibling(?string $parentId, string $name): ?AssetFolder
    {
        $key = AssetReview::foldName($name);

        foreach ($this->folders() as $folder) {
            if ($folder->parent_id === $parentId && AssetReview::foldName((string) $folder->name) === $key) {
                return $folder;
            }
        }

        return null;
    }

    /** @return array<string, AssetTag> */
    public function tags(): array
    {
        return $this->tags ??= AssetTag::query()->get(['id', 'name'])->keyBy('id')->all();
    }

    public function tag(string $id): ?AssetTag
    {
        return $this->tags()[$id] ?? null;
    }

    public function tagNamed(string $name): ?AssetTag
    {
        $key = AssetReview::foldName($name);

        foreach ($this->tags() as $tag) {
            if (AssetReview::foldName((string) $tag->name) === $key) {
                return $tag;
            }
        }

        return null;
    }

    /** Forget loaded folders and tags after creating one. */
    public function forget(): void
    {
        $this->folders = null;
        $this->tags = null;
    }
}
