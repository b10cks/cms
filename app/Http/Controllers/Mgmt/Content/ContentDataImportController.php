<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\DTOs\ImportExport\ImportResult;
use App\Enums\ContentTranslationImportMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ImportContentDataRequest;
use App\Models\Management\Space;
use App\Services\Ai\Dto\StreamEvent;
use App\Services\Ai\Support\AiSseStream;
use App\Services\ContentData\ContentDataImportExportService;
use App\Services\ImportExport\Exceptions\ImportValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Imports content translations. Clients sending `Accept: text/event-stream` get
 * per-document progress and no time limit, which large files need: a single JSON
 * response dies at the PHP execution limit long before a few hundred entries
 * finish publishing.
 */
class ContentDataImportController extends Controller
{
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

        try {
            $run = $service->importContents(
                $space,
                $request->file('file'),
                $request->getContentDataFormat(),
                $mode,
                $request->shouldCreateMissing(),
                $user,
                gridMode: $request->isGridImport(),
            );

            if ($request->wantsEventStream()) {
                return AiSseStream::response(
                    fn (): \Generator => $this->events($run),
                    ['endpoint' => 'content-import', 'space' => $space->id],
                    stopOnDisconnect: false,
                );
            }

            foreach ($run as $_) {
                // Drain: the JSON response only carries the final result.
            }

            return response()->json($run->getReturn()->toArray());
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

    /**
     * @param  \Generator<int, array{processed: int, total: int}, mixed, ImportResult>  $run
     * @return \Generator<StreamEvent>
     */
    private function events(\Generator $run): \Generator
    {
        foreach ($run as $progress) {
            yield StreamEvent::status(json_encode($progress));
        }

        yield StreamEvent::done('', $run->getReturn()->toArray());
    }
}
