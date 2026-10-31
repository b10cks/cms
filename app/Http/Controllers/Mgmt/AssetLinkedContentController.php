<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\LinkedAssetContentResource;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\Content;
use App\Services\Asset\AssetUsageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AssetLinkedContentController extends Controller
{
    public function __invoke(
        Space $space,
        Asset $asset,
        Request $request,
        AssetUsageService $assetUsageService
    ): ResourceCollection {
        $this->authorizeSpace($space, 'assets.view');

        $usage = $assetUsageService->linkedContents($asset);

        $contents = Content::query()
            ->whereKey(array_keys($usage))
            ->with('block:id,name,icon,color,slug')
            ->orderBy('name')
            ->orderBy('language_iso')
            ->paginate($this->perPage($request, 10));

        foreach ($contents as $content) {
            $content->setAttribute('asset_usage', $usage[$content->id]);
        }

        return LinkedAssetContentResource::collection($contents);
    }
}
