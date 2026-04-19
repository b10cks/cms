<?php

namespace App\Http\Controllers\Mgmt;

use App\Enums\ImportExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AssetFilter;
use App\Http\Requests\Asset\ExportAssetDataRequest;
use App\Models\Management\Space;
use App\Services\AssetData\AssetDataExportImportService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AssetDataExportController extends Controller
{
    public function __invoke(
        ExportAssetDataRequest $request,
        Space $space,
        AssetDataExportImportService $service
    ): Response {
        try {
            $format = ImportExportFormat::from($request->validated('as'));
            $filter = new AssetFilter($request->all());

            return $service->exportAssets($space, $format, $filter);
        } catch (\Throwable $e) {
            Log::error('Asset data export failed', [
                'space_id' => $space->id,
                'format' => $request->input('as'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            abort(500, 'Failed to export asset data: ' . $e->getMessage());
        }
    }
}
