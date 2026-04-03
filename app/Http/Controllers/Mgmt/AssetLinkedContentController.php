<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\LinkedAssetContentResource;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Asset\AssetUsageService;
use App\Services\Auth\AuthorizationService;
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
        abort_unless(
            app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'assets.view'),
            403
        );

        $contents = $assetUsageService
            ->getLinkedContentsQuery($asset)
            ->orderBy('name')
            ->orderBy('language_iso')
            ->paginate($request->integer('per_page', 10));

        return LinkedAssetContentResource::collection($contents);
    }
}
