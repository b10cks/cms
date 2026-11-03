<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\RespondsWithImportRun;
use App\Http\Requests\DataEntry\ImportDataEntryDataRequest;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\DataEntryData\DataEntryDataImportExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataEntryDataImportController extends Controller
{
    use RespondsWithImportRun;

    public function __invoke(
        ImportDataEntryDataRequest $request,
        Space $space,
        DataSource $dataSource,
        DataEntryDataImportExportService $service,
    ): JsonResponse|StreamedResponse {
        $this->authorize('create', [DataEntry::class, $dataSource, $space]);

        return $this->importResponse(
            $request,
            'data entries',
            fn () => $service->importEntries($space, $dataSource, $request->file('file'), $request->getDataEntryDataFormat(), $request->getImportMode()),
            ['space_id' => $space->id, 'data_source_id' => $dataSource->id],
        );
    }
}
