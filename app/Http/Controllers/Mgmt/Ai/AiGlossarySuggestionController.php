<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Actions\Ai\AcceptAiGlossarySuggestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AcceptAiGlossarySuggestionRequest;
use App\Http\Resources\Management\Ai\AiGlossaryTermResource;
use App\Models\Management\Space;
use App\Models\Space\AiGlossarySuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Learning suggestions from the suggestions thread: accept into a glossary or dismiss. */
class AiGlossarySuggestionController extends Controller
{
    public function accept(AcceptAiGlossarySuggestionRequest $request, Space $space, string $suggestion, AcceptAiGlossarySuggestion $action): AiGlossaryTermResource
    {
        $term = $action->accept($this->findPending($space, $suggestion), $space, $request->user(), $request->string('glossary_id')->value());

        return new AiGlossaryTermResource($term);
    }

    public function dismiss(Request $request, Space $space, string $suggestion, AcceptAiGlossarySuggestion $action): JsonResponse
    {
        $action->dismiss($this->findPending($space, $suggestion), $request->user());

        return response()->json(null, 204);
    }

    private function findPending(Space $space, string $id): AiGlossarySuggestion
    {
        $this->authorizeSpace($space, 'ai.manage');

        return AiGlossarySuggestion::query()
            ->where('space_id', $space->id)
            ->where('status', AiGlossarySuggestion::STATUS_PENDING)
            ->findOrFail($id);
    }
}
