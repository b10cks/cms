<?php

namespace App\Services\Search;

use App\Support\SpaceContext;
use App\Contracts\Search\SearchDriverInterface;
use App\Enums\SearchDriver;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SearchService
{
    protected array $drivers = [];

    public function __construct(
        protected MySqlSearchDriver $mySqlDriver,
        protected OpenSearchDriver $openSearchDriver
    ) {
        $this->drivers[SearchDriver::MYSQL->value] = $this->mySqlDriver;
        $this->drivers[SearchDriver::OPENSEARCH->value] = $this->openSearchDriver;
    }

    public function getDriver(Space $space): SearchDriverInterface
    {
        $driverType = $this->getSearchDriver($space);

        if (!isset($this->drivers[$driverType->value])) {
            throw new InvalidArgumentException("Search driver [{$driverType->value}] is not supported.");
        }

        return $this->drivers[$driverType->value];
    }

    public function indexContent(Content $content, Space $space): void
    {
        $driver = $this->getDriver($space);
        $driver->indexContent($content, $space);
    }

    public function removeContent(Content $content, Space $space): void
    {
        $driver = $this->getDriver($space);
        $driver->removeContent($content, $space);
    }

    public function switchDriver(Space $space, SearchDriver $newDriver): void
    {
        $oldDriver = $this->getSearchDriver($space);
        if ($oldDriver === $newDriver) {
            return;
        }

        if ($oldDriver->isOpenSearch()) {
            $this->drivers[$oldDriver->value]->deleteIndex($space);
        }

        $space->settings->search_driver = $newDriver->value;
        $space->save();

        if ($newDriver->isOpenSearch()) {
            $this->drivers[$newDriver->value]->createIndex($space);
        }
    }

    public function reindexSpace(Space $space): void
    {
        $restore = SpaceContext::enter($space);

        try {
            $driver = $this->getDriver($space);
            $driver->reindexSpace($space);
        } finally {
            $restore();
        }
    }

    public function search(Space $space, string $query, string $language, int $limit = 20, int $offset = 0): array
    {
        $driver = $this->getDriver($space);
        $searchResults = $driver->search($space, $query, $language, $limit, $offset);

        $hits = collect($searchResults['results'] ?? []);
        if ($hits->isEmpty()) {
            return [
                'total' => (int) ($searchResults['total'] ?? 0),
                'results' => [],
            ];
        }

        $contents = $this->hydrateContents($hits);

        $results = $hits
            ->map(function (array $hit) use ($contents): ?Content {
                $content = $contents->get($hit['id'] ?? null);

                if (!$content) {
                    return null;
                }

                $content->setAttribute('relevance_score', isset($hit['relevance_score']) ? (float) $hit['relevance_score'] : 0.0);

                return $content;
            })
            ->filter()
            ->values()
            ->all();

        return [
            'total' => (int) ($searchResults['total'] ?? 0),
            'results' => $results,
        ];
    }

    protected function hydrateContents(Collection $hits): Collection
    {
        $ids = $hits
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        $contents = Content::query()
            ->whereIn('contents.id', $ids)
            ->whereNotNull('contents.published_at')
            ->select([
                ...Content::deliveryColumns('contents.'),
                'content_versions.content',
                'content_versions.relation_ids',
                'content_versions.asset_ids',
                'content_versions.link_ids',
            ])
            ->leftJoin('content_versions', 'contents.published_version_id', '=', 'content_versions.id')
            ->with([
                'i18n_parent',
                'i18n_children',
                'i18n_siblings',
                'block',
            ])
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->mapWithKeys(fn(string $id) => [$id => $contents->get($id)])
            ->filter();
    }

    protected function getSearchDriver(Space $space): SearchDriver
    {
        return $space->getSearchDriver();
    }
}
