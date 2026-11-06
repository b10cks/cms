<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AnswerAiOperationQuestionsRequest;
use App\Http\Resources\Management\Ai\AiOperationQuestionResource;
use App\Http\Resources\Management\Ai\AiOperationResource;
use App\Models\Management\Space;
use App\Models\Space\AiOperation;
use App\Services\Ai\Operations\OperationQuestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The questions an operation collected and the answers that resume the outputs
 * they name. Lists are paged, however large the scope. Reading follows
 * AiOperationPolicy::view(), answering AiOperationPolicy::update().
 */
class AiOperationQuestionController extends Controller
{
    /**
     * Open question groups, with `summary` over the whole operation.
     *
     * @apiQuery page integer
     * @apiQuery per_page integer At most 200.
     *
     * @apiResponse 200 @AiOperationQuestionPage
     */
    public function index(Request $request, Space $space, string $operation, OperationQuestions $questions): AnonymousResourceCollection
    {
        $overview = $questions->overview($this->find($space, $operation, 'view'));

        return AiOperationQuestionResource::collection($this->page($request, $overview['groups'], 20))
            ->additional(['summary' => $overview['summary']]);
    }

    /**
     * The exact outputs one question asks about. A question already answered has none.
     *
     * @apiQuery page integer
     * @apiQuery per_page integer At most 200.
     *
     * @apiResponse 200 page:@AiOperationQuestionMember
     */
    public function members(Request $request, Space $space, string $operation, string $question, OperationQuestions $questions): AnonymousResourceCollection
    {
        return JsonResource::collection($this->page($request, $questions->members($this->find($space, $operation, 'view'), $question), 50));
    }

    /**
     * Answers given so far, newest first.
     *
     * @apiQuery page integer
     * @apiQuery per_page integer At most 200.
     *
     * @apiResponse 200 page:@AiOperationAnsweredQuestion
     */
    public function answered(Request $request, Space $space, string $operation, OperationQuestions $questions): AnonymousResourceCollection
    {
        return JsonResource::collection($this->page($request, $questions->answered($this->find($space, $operation, 'view')), 20));
    }

    /**
     * Record answers and resume the outputs they name, which starts paid batches within the budget. An answer never raises the budget.
     *
     * Partial, repeated and stale answers are safe; `results` says what each did.
     *
     * @apiResponse 200 data:@AiOperationAnswerResults
     * @apiResponse 409 @AiOperationConflict `questions_closed` while work is running or paused, or after cancelling.
     * @apiResponse 429 @AiConcurrencyLimit Nothing was stored.
     */
    public function answer(AnswerAiOperationQuestionsRequest $request, Space $space, string $operation, OperationQuestions $questions): JsonResponse
    {
        $model = $this->find($space, $operation, 'update');
        $results = $questions->answer($model, $request->user(), $request->validated('answers'));

        return response()->json(['data' => [
            'results' => $results,
            'operation' => new AiOperationResource($model->refresh()),
        ]]);
    }

    private function find(Space $space, string $id, string $ability): AiOperation
    {
        $operation = AiOperation::query()->where('space_id', $space->id)->findOrFail($id);
        $this->authorize($ability, [$operation, $space]);

        return $operation;
    }

    /**
     * @template T
     *
     * @param  list<T>  $rows
     * @return LengthAwarePaginator<int, T>
     */
    private function page(Request $request, array $rows, int $default): LengthAwarePaginator
    {
        $perPage = $this->perPage($request, $default, 200);
        $page = max(1, $request->integer('page', 1));

        return new LengthAwarePaginator(\array_slice($rows, ($page - 1) * $perPage, $perPage), \count($rows), $perPage, $page, ['path' => $request->url()]);
    }
}
