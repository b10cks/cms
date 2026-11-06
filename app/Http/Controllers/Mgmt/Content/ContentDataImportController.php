<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Enums\ContentTranslationImportMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\RespondsWithImportRun;
use App\Http\Requests\Content\ImportContentDataRequest;
use App\Models\Management\Space;
use App\Services\ContentData\ContentDataImportExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentDataImportController extends Controller
{
    use RespondsWithImportRun;

    public function __invoke(
        ImportContentDataRequest $request,
        Space $space,
        ContentDataImportExportService $service
    ): JsonResponse|StreamedResponse {
        $user = auth()->user();

        $this->authorizeSpace($space, 'content.manage');

        $mode = $request->getImportMode();

        if ($mode === ContentTranslationImportMode::PUBLISH) {
            $this->authorizeSpace($space, 'content.publish');
        }

        return $this->importResponse(
            $request,
            'content translations',
            fn () => $service->importContents(
                $space,
                $request->file('file'),
                $request->getContentDataFormat(),
                $mode,
                $request->shouldCreateMissing(),
                $user,
                gridMode: $request->isGridImport(),
            ),
            ['space_id' => $space->id],
        );
    }
}
