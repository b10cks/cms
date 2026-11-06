<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\RespondsWithImportRun;
use App\Http\Requests\Redirect\ImportRedirectDataRequest;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use App\Services\RedirectData\RedirectDataImportExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RedirectDataImportController extends Controller
{
    use RespondsWithImportRun;

    public function __invoke(
        ImportRedirectDataRequest $request,
        Space $space,
        RedirectDataImportExportService $service,
    ): JsonResponse|StreamedResponse {
        $this->authorize('create', [Redirect::class, $space]);

        return $this->importResponse(
            $request,
            'redirects',
            fn () => $service->importRedirects($space, $request->file('file'), $request->getRedirectDataFormat(), $request->getImportMode()),
            ['space_id' => $space->id],
        );
    }
}
