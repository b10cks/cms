<?php

namespace App\Http\Controllers\Mgmt;

use App\Enums\AssetDataFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Asset\ImportAssetDataRequest;
use App\Models\Management\Space;
use App\Services\AssetData\AssetDataExportImportService;
use App\Services\AssetData\Exceptions\ImportValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AssetDataImportController extends Controller
{
    public function __invoke(
        ImportAssetDataRequest $request,
        Space $space,
        AssetDataExportImportService $service
    ): JsonResponse {
        try {
            $format = $request->getAssetDataFormat();
            $file = $request->file('file');

            $result = $service->importAssets($space, $file, $format);

            return response()->json($result->toArray());

        } catch (ImportValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Asset data import failed', [
                'space_id' => $space->id,
                'file' => $request->file('file')->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to import asset data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
