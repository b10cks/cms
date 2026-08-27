<?php

namespace App\Actions\Asset;

use App\Models\Management\Space;
use App\Models\Space\AssetFolder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Normalizer;

/**
 * Resolves slash-separated folder paths into asset folders under a parent,
 * creating what is missing. Names are compared after Unicode NFC normalization
 * and case folding (in PHP, so MySQL and sqlite behave the same), soft-deleted
 * folders are ignored and never restored.
 *
 * The transaction makes the whole resolution atomic: a failure halfway through
 * leaves no partial tree. It does not provide mutual exclusion. There is no
 * unique index on `(parent_id, name)` and the child lookup is an unlocked read,
 * so two callers resolving `Brand/Logos` at the same instant could each insert
 * their own `Brand`. A per-space cache lock serializes calls to this action,
 * which closes that window for the drop path. It does not cover a plain folder
 * create through the normal UI racing an ensure-paths call; that one still
 * needs the unique index we do not have.
 *
 * @phpstan-type EnsureResult array{
 *     paths: array<string, string|null>,
 *     folders: Collection<int, AssetFolder>,
 *     renamed: list<array{from: string, to: string}>,
 * }
 */
class EnsureAssetFolderPaths
{
    /** Mirrors `asset_folders.name` being varchar(100). */
    private const NAME_MAX_LENGTH = 100;

    /**
     * Long enough to outlast the work it guards, short enough to expire well
     * before anything else gives up on the request.
     *
     * `EnsureAssetFolderPathsRequest::MAX_SEGMENTS` caps a payload at 2000
     * folder levels, measured at ~4.8 s of work when none of them exist yet.
     * Even several times slower than the measurement, that finishes inside this
     * TTL, so the lock cannot lapse with the transaction still open and let a
     * second drop create the duplicate folder it exists to prevent. It is also
     * short of the 59 s
     * `max_execution_time`: if a request is ever killed mid-transaction and the
     * release in `block()` never runs, the space is blocked for at most this
     * long rather than for a stretch nobody can wait out.
     */
    private const LOCK_TTL_SECONDS = 30;

    /**
     * How long a queued caller waits. A legitimate large drop can hold the lock
     * for most of the TTL, so giving up after a few seconds would 503 callers
     * that only needed to wait their turn. Waiting plus the caller's own work
     * still fits inside `max_execution_time`.
     */
    private const LOCK_WAIT_SECONDS = 20;

    /**
     * @param  list<string>  $paths
     * @return EnsureResult
     */
    public function execute(Space $space, ?string $parentId, array $paths): array
    {
        $lock = Cache::lock("asset-folder-paths:{$space->id}", self::LOCK_TTL_SECONDS);

        try {
            return $lock->block(self::LOCK_WAIT_SECONDS, fn (): array => $this->resolve($parentId, $paths));
        } catch (LockTimeoutException) {
            // Another drop is mirroring a tree into this space right now. Running
            // anyway is what creates duplicate folders, so ask for a retry.
            abort(
                503,
                'Another folder upload is still mirroring folders into this space. '
                . 'Nothing was lost — wait for it to finish and upload again.',
                ['Retry-After' => (string) self::LOCK_TTL_SECONDS],
            );
        }
    }

    /**
     * @param  list<string>  $paths
     * @return EnsureResult
     */
    private function resolve(?string $parentId, array $paths): array
    {
        return new AssetFolder()->getConnection()->transaction(function () use ($parentId, $paths): array {
            $resolved = [];
            $touched = collect();
            $renamed = [];

            /** @var array<string, string> $sanitized */
            $sanitized = [];

            /** @var array<string, Collection<string, AssetFolder>> $childrenByParent */
            $childrenByParent = [];

            foreach ($paths as $path) {
                // Only genuinely empty segments (leading, trailing or doubled
                // slashes) drop out. A folder literally named "   " exists on
                // disk, so it becomes a real folder under the placeholder name
                // instead of collapsing into its parent.
                $segments = array_values(array_filter(
                    explode('/', $path),
                    static fn (string $segment): bool => $segment !== '',
                ));

                $currentParentId = $parentId;

                foreach ($segments as $segment) {
                    $segment = $this->normalize($segment);
                    $name = $this->sanitizeSegment($segment, $sanitized);

                    if ($name !== $segment) {
                        $renamed[$segment] = $name;
                    }

                    $cacheKey = $currentParentId ?? '';
                    $children = $childrenByParent[$cacheKey] ??= AssetFolder::query()
                        ->where('parent_id', $currentParentId)
                        ->get()
                        ->keyBy(fn (AssetFolder $folder): string => $this->foldKey($folder->name ?? ''));

                    $folder = $children->get($this->foldKey($name));

                    if (!$folder) {
                        $folder = new AssetFolder([
                            'name' => $name,
                            'parent_id' => $currentParentId,
                        ]);
                        $folder->save();

                        $childrenByParent[$cacheKey]->put($this->foldKey($name), $folder);
                    }

                    $touched->put($folder->id, $folder);
                    $currentParentId = $folder->id;
                }

                $resolved[$path] = $currentParentId;
            }

            return [
                'paths' => $resolved,
                'folders' => $touched->values(),
                'renamed' => collect($renamed)
                    ->map(static fn (string $to, string $from): array => ['from' => $from, 'to' => $to])
                    ->values()
                    ->all(),
            ];
        });
    }

    /**
     * Runs a segment through the model's own name purification, then trims,
     * truncates to the column length and falls back to a placeholder when
     * purification leaves nothing.
     *
     * Reads the raw attribute rather than `$probe->name`: `name` purifies on
     * both get and set, so the accessor would run HTMLPurifier a second time
     * over text the mutator has already cleaned. The stored value is what the
     * column would hold, which is exactly what this needs.
     *
     * Segments repeat heavily across a real tree (every path under `Brand/`
     * carries `Brand`), so results are memoized for the length of one call.
     *
     * @param  array<string, string>  $memo
     */
    private function sanitizeSegment(string $segment, array &$memo): string
    {
        if (isset($memo[$segment])) {
            return $memo[$segment];
        }

        $probe = new AssetFolder;
        $probe->name = $segment;

        $name = trim(mb_substr(trim((string) ($probe->getAttributes()['name'] ?? '')), 0, self::NAME_MAX_LENGTH));

        return $memo[$segment] = $name === '' ? 'folder' : $name;
    }

    /**
     * The comparison key for merging siblings: one Unicode form, one case.
     * macOS hands the browser decomposed names, so an NFD "Café" from a drop
     * has to find the NFC "Café" a UI create left behind.
     */
    private function foldKey(string $name): string
    {
        return mb_strtolower($this->normalize($name));
    }

    /**
     * NFC-normalizes when ext-intl is available. The extension is not a declared
     * requirement, so without it names are compared as they arrive and a drop
     * from macOS can still produce a second, visually identical folder.
     */
    private function normalize(string $value): string
    {
        if (!class_exists(Normalizer::class)) {
            return $value;
        }

        return Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
    }
}
