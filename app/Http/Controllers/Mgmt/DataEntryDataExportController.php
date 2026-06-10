<?php

namespace App\Http\Controllers\Mgmt;

use App\Enums\ImportExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ExportDataEntryDataRequest;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\DataEntryData\DataEntryDataImportExportService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DataEntryDataExportController extends Controller
{
    public function __invoke(
        ExportDataEntryDataRequest $request,
        Space $space,
        DataSource $dataSource,
        DataEntryDataImportExportService $service,
    ): Response {
        $this->authorize('viewAny', [DataEntry::class, $dataSource, $space]);

        try {
            $format = ImportExportFormat::from($request->validated('as'));

            return $service->exportEntries($space, $dataSource, $format);
        } catch (\Throwable $e) {
            Log::error('Data entry export failed', [
                'space_id' => $space->id,
                'data_source_id' => $dataSource->id,
                'format' => $request->input('as'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            abort(500, 'Failed to export data entries: ' . $e->getMessage());
        }
    }
}
