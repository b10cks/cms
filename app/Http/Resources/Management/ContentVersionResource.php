<?php

namespace App\Http\Resources\Management;

use App\Models\Space\ContentVersion;
use App\Services\Content\Diff\ArrayDiffService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentVersion
 */
class ContentVersionResource extends ContentVersionListResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
                'diff' => $this->getDiff(),
            ];
    }

    protected function getDiff()
    {
        return app(ArrayDiffService::class)->diff($this->parent->content ?? [], $this->content);
    }
}
