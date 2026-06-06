<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Redirect\ImportRedirectDataRequest;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use App\Services\ImportExport\Exceptions\ImportValidationException;
use App\Services\RedirectData\RedirectDataImportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RedirectDataImportController extends Controller
{
    public function __invoke(
        ImportRedirectDataRequest $request,
        Space $space,
        RedirectDataImportExportService $service,
    ): JsonResponse {
        $this->authorize('create', [Redirect::class, $space]);

        try {
            $format = $request->getRedirectDataFormat();
            $file = $request->file('file');
            $mode = $request->getImportMode();

            $result = $service->importRedirects($space, $file, $format, $mode);

            return response()->json($result->toArray());
        } catch (ImportValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Redirect import failed', [
                'space_id' => $space->id,
                'file' => $request->file('file')?->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to import redirects: ' . $e->getMessage(),
            ], 500);
        }
    }
}
