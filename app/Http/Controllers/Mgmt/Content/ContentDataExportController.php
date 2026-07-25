<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Enums\ImportExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentFilter;
use App\Http\Filters\Mgmt\ContentMassEditFilter;
use App\Http\Requests\Content\ExportContentDataRequest;
use App\Models\Management\Space;
use App\Services\ContentData\ContentDataImportExportService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ContentDataExportController extends Controller
{
    public function __invoke(
        ExportContentDataRequest $request,
        Space $space,
        ContentDataImportExportService $service
    ): Response {
        $this->authorizeSpace($space, 'content.view');

        try {
            $format = ImportExportFormat::from($request->validated('as'));
            // The mass-edit grid sends operator-prefixed filters (`grid=1`); the
            // classic export dialog sends plain ContentFilter values.
            $filter = $request->boolean('grid')
                ? new ContentMassEditFilter($request->all())
                : new ContentFilter($request->all());

            return $service->exportContents(
                $space,
                $format,
                $filter,
                $request->getFieldKeys(),
                $request->getLanguageFilter(),
                gridMode: $request->boolean('grid'),
            );
        } catch (\Throwable $e) {
            Log::error('Content translation export failed', [
                'space_id' => $space->id,
                'format' => $request->input('as'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            abort(500, 'Failed to export content translations: '.$e->getMessage());
        }
    }
}
