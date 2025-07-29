<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class DeleteContent
{
    public function execute(Content $content, Space $space, Authenticatable|User|null $owner)
    {
        \DB::transaction(function () use ($content, $owner, $space) {
            $this->deleteChildren($content);
            $content->delete();
            $space->touch('content_updated_at');
        });
    }

    private function deleteChildren(Content $content)
    {
        foreach ($content->children as $child) {
            $this->deleteChildren($child);
            $child->delete();
        }
    }
}
