<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ImportDataEntryDataRequest;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\DataEntryData\DataEntryDataImportExportService;
use App\Services\ImportExport\Exceptions\ImportValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DataEntryDataImportController extends Controller
{
    public function __invoke(
        ImportDataEntryDataRequest $request,
        Space $space,
        DataSource $dataSource,
        DataEntryDataImportExportService $service,
    ): JsonResponse {
        $this->authorize('create', [DataEntry::class, $dataSource, $space]);

        try {
            $format = $request->getDataEntryDataFormat();
            $file = $request->file('file');
            $mode = $request->getImportMode();

            $result = $service->importEntries($space, $dataSource, $file, $format, $mode);

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
            Log::error('Data entry import failed', [
                'space_id' => $space->id,
                'data_source_id' => $dataSource->id,
                'file' => $request->file('file')?->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to import data entries: ' . $e->getMessage(),
            ], 500);
        }
    }
}
