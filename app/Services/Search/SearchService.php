<?php

namespace App\Services\Search;

use App\Contracts\Search\SearchDriverInterface;
use App\Enums\SearchDriver;
use App\Models\Management\Space;
use App\Models\Space\Content;
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

        $space->settings = ($space->settings ?? []) + [
            'search_driver' => $newDriver->value
        ];
        $space->save();

        if ($newDriver->isOpenSearch()) {
            $this->drivers[$newDriver->value]->createIndex($space);
        }
    }

    public function reindexSpace(Space $space): void
    {
        app()->offsetSet('currentSpace', $space);
        $driver = $this->getDriver($space);
        $driver->reindexSpace($space);
    }

    public function search(Space $space, string $query, int $limit = 20, int $offset = 0): array
    {
        $driver = $this->getDriver($space);
        return $driver->search($space, $query, $limit, $offset);
    }

    protected function getSearchDriver(Space $space): SearchDriver
    {
        return $space->getSearchDriver();
    }
}
