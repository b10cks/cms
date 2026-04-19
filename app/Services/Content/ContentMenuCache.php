<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ContentMenuCache
{
    private const int MENU_TTL_SECONDS = 86400;

    private const int VERSION_TTL_SECONDS = 2592000;

    /**
     * @template T
     *
     * @param  array<string, mixed>  $query
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function remember(Space|string $space, array $query, \Closure $callback): mixed
    {
        $spaceId = $this->normalizeSpaceId($space);
        $version = Cache::remember(
            $this->versionKey($spaceId),
            self::VERSION_TTL_SECONDS,
            fn (): string => (string) Str::ulid(),
        );

        return Cache::remember(
            $this->menuKey($spaceId, $version, $query),
            self::MENU_TTL_SECONDS,
            $callback,
        );
    }

    public function invalidate(Space|string $space): void
    {
        Cache::put(
            $this->versionKey($this->normalizeSpaceId($space)),
            (string) Str::ulid(),
            self::VERSION_TTL_SECONDS,
        );
    }

    public function versionKey(Space|string $space): string
    {
        return 'spaces:'.$this->normalizeSpaceId($space).':content-menu:version';
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function menuKey(string $spaceId, string $version, array $query): string
    {
        return sprintf(
            'spaces:%s:content-menu:%s:%s',
            $spaceId,
            $version,
            sha1(json_encode($this->normalizeQuery($query), JSON_THROW_ON_ERROR)),
        );
    }

    private function normalizeSpaceId(Space|string $space): string
    {
        return $space instanceof Space ? $space->id : $space;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function normalizeQuery(array $query): array
    {
        ksort($query);

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $query[$key] = $this->normalizeQuery($value);
            }
        }

        return $query;
    }
}
