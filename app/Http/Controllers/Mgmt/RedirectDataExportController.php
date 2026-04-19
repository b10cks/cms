<?php

namespace App\Http\Controllers\Mgmt;

use App\Enums\ImportExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\RedirectFilter;
use App\Http\Requests\Redirect\ExportRedirectDataRequest;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use App\Services\RedirectData\RedirectDataImportExportService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RedirectDataExportController extends Controller
{
    public function __invoke(
        ExportRedirectDataRequest $request,
        Space $space,
        RedirectDataImportExportService $service,
    ): Response {
        $this->authorize('viewAny', [Redirect::class, $space]);

        try {
            $format = ImportExportFormat::from($request->validated('as'));
            $filter = RedirectFilter::fromRequest($request);

            return $service->exportRedirects($space, $format, $filter);
        } catch (\Throwable $e) {
            Log::error('Redirect export failed', [
                'space_id' => $space->id,
                'format' => $request->input('as'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            abort(500, 'Failed to export redirects: ' . $e->getMessage());
        }
    }
}
