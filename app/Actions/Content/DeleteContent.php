<?php

namespace App\Actions\Content;

use App\Models\Space\Content;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class DeleteContent
{
    public function execute(Content $content, Authenticatable|User|null $owner)
    {
        \DB::transaction(function () use ($content, $owner) {
            $this->deleteChildren($content);
            $content->delete();
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
