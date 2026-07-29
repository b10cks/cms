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
        $content->getConnection()->transaction(function () use ($content, $owner, $space) {
            $this->deleteChildren($content, $space);
            $this->searchService->removeContent($content, $space);
            $content->delete();
            $space->touch('content_updated_at');
        });
    }

    private function deleteChildren(Content $content, Space $space)
    {
        // Collect the subtree with one query per level instead of one per
        // node, then delete deepest level first so children always go before
        // their parent. Deletes stay per-model: the soft-delete hooks (serial
        // pool, ContentDeleted event, audit) are load-bearing.
        $levels = [];
        $level = $content->children;

        while ($level->isNotEmpty()) {
            $levels[] = $level;
            $level = Content::query()
                ->whereIn('parent_id', $level->pluck('id'))
                ->orderBy('position')
                ->orderBy('name')
                ->orderBy('id')
                ->get();
        }

        foreach (array_reverse($levels) as $levelRows) {
            foreach ($levelRows as $child) {
                $this->searchService->removeContent($child, $space);
                $child->delete();
            }
        }
    }
}
