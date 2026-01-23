<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;

class DeleteContent
{
    public function __construct(
        protected SearchService $searchService
    ) {
    }

    public function execute(Content $content, Space $space, Authenticatable|User|null $owner)
    {
        \DB::transaction(function () use ($content, $owner, $space) {
            $this->deleteChildren($content, $space);
            $this->searchService->removeContent($content, $space);
            $content->delete();
            $space->touch('content_updated_at');
        });
    }

    private function deleteChildren(Content $content, Space $space)
    {
        foreach ($content->children as $child) {
            $this->deleteChildren($child, $space);
            $this->searchService->removeContent($child, $space);
            $child->delete();
        }
    }
}
