<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Icon\ImportIconDataRequest;
use App\Models\Management\Space;
use App\Services\IconData\IconDataImportService;
use App\Services\ImportExport\Exceptions\ImportValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class IconDataImportController extends Controller
{
    public function __invoke(
        ImportIconDataRequest $request,
        Space $space,
        IconDataImportService $service,
    ): JsonResponse {
        $this->authorizeSpace($space, 'icons.manage');

        try {
            $result = $service->importIcons($space, $request->file('file'), $request->getImportMode());

            return response()->json($result->toArray());
        } catch (ImportValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Icon import failed', [
                'space_id' => $space->id,
                'file' => $request->file('file')?->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to import icons: ' . $e->getMessage(),
            ], 500);
        }
    }
}
