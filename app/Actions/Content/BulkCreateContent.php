<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class BulkCreateContent
{
    public function __construct(
        protected CreateContent $createContent
    ) {
    }

    public function execute(array $items, Space $space, Authenticatable|User|null $owner): array
    {
        $createdItems = [];

        DB::transaction(function () use ($items, $space, $owner, &$createdItems) {
            $tempIdMap = [];

            foreach ($items as $item) {
                // Resolve parent_id if it references a temp_id
                $parentId = $item['parent_id'] ?? null;
                if ($parentId && isset($tempIdMap[$parentId])) {
                    $parentId = $tempIdMap[$parentId];
                    $item['parent_id'] = $parentId;
                }

                // Validate block_id exists
                if (empty($item['block_id'])) {
                    throw new \InvalidArgumentException("Missing block_id for item: {$item['name']}");
                }

                $tmpId = $item['temp_id'] ?? null;
                unset($item['temp_id']);
                $content = new Content();
                $this->createContent->execute($item, $content, $space, $owner);

                // Map temp_id to real ID for subsequent items
                if ($tmpId !== null) {
                    $tempIdMap[$tmpId] = $content->id;
                }

                $createdItems[] = [
                    'temp_id' => $tmpId,
                    'id' => $content->id,
                    'name' => $content->name,
                    'slug' => $content->slug,
                    'parent_id' => $content->parent_id,
                ];
            }
        });

        return $createdItems;
    }
}
