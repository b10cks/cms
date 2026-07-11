<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Enums\ContentTranslationImportMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ImportContentDataRequest;
use App\Models\Management\Space;
use App\Services\Auth\AuthorizationService;
use App\Services\ContentData\ContentDataImportExportService;
use App\Services\ImportExport\Exceptions\ImportValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ContentDataImportController extends Controller
{
    public function __invoke(
        ImportContentDataRequest $request,
        Space $space,
        ContentDataImportExportService $service
    ): JsonResponse {
        $authorization = app(AuthorizationService::class);
        $user = auth()->user();

        abort_unless($authorization->canInSpace($user, $space, 'content.manage'), 403);

        $mode = $request->getImportMode();

        if ($mode === ContentTranslationImportMode::PUBLISH) {
            abort_unless($authorization->canInSpace($user, $space, 'content.publish'), 403);
        }

        try {
            $result = $service->importContents(
                $space,
                $request->file('file'),
                $request->getContentDataFormat(),
                $mode,
                $request->shouldCreateMissing(),
                $user,
            );

            return response()->json($result->toArray());
        } catch (ImportValidationException | \InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Content translation import failed', [
                'space_id' => $space->id,
                'file' => $request->file('file')?->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to import content translations: ' . $e->getMessage(),
            ], 500);
        }
    }
}
