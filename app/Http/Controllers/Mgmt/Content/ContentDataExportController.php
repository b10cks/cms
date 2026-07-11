<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Enums\ImportExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentFilter;
use App\Http\Requests\Content\ExportContentDataRequest;
use App\Models\Management\Space;
use App\Services\Auth\AuthorizationService;
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
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'content.view'), 403);

        try {
            $format = ImportExportFormat::from($request->validated('as'));
            $filter = new ContentFilter($request->all());

            return $service->exportContents($space, $format, $filter);
        } catch (\Throwable $e) {
            Log::error('Content translation export failed', [
                'space_id' => $space->id,
                'format' => $request->input('as'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            abort(500, 'Failed to export content translations: ' . $e->getMessage());
        }
    }
}
