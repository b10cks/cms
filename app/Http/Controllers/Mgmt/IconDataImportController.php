<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\RespondsWithImportRun;
use App\Http\Requests\Icon\ImportIconDataRequest;
use App\Models\Management\Space;
use App\Services\IconData\IconDataImportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IconDataImportController extends Controller
{
    use RespondsWithImportRun;

    public function __invoke(
        ImportIconDataRequest $request,
        Space $space,
        IconDataImportService $service,
    ): JsonResponse|StreamedResponse {
        $this->authorizeSpace($space, 'icons.manage');

        return $this->importResponse(
            $request,
            'icons',
            fn () => $service->importIcons($space, $request->file('file'), $request->getImportMode()),
            ['space_id' => $space->id],
        );
    }
}
