<?php

namespace App\Contracts\Search;

use App\Models\Management\Space;
use App\Models\Space\Content;

interface SearchDriverInterface
{
    public function indexContent(Content $content, Space $space): void;

    public function removeContent(Content $content, Space $space): void;

    public function createIndex(Space $space): void;

    public function deleteIndex(Space $space): void;

    public function reindexSpace(Space $space): void;

    public function search(Space $space, string $query, int $limit = 20, int $offset = 0): array;
}
