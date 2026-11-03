<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\RespondsWithImportRun;
use App\Http\Requests\Asset\ImportAssetDataRequest;
use App\Models\Management\Space;
use App\Services\AssetData\AssetDataExportImportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetDataImportController extends Controller
{
    use RespondsWithImportRun;

    public function __invoke(
        ImportAssetDataRequest $request,
        Space $space,
        AssetDataExportImportService $service
    ): JsonResponse|StreamedResponse {
        $this->authorizeSpace($space, 'assets.manage');

        return $this->importResponse(
            $request,
            'asset data',
            fn () => $service->importAssets($space, $request->file('file'), $request->getAssetDataFormat()),
            ['space_id' => $space->id],
        );
    }
}
