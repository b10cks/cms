<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\ResolvesAiOwner;
use App\Http\Requests\Ai\BulkAiGlossaryConceptRowsRequest;
use App\Http\Requests\Ai\CheckAiGlossaryTextRequest;
use App\Http\Requests\Ai\SaveAiGlossaryConceptRowRequest;
use App\Http\Resources\Management\Ai\AiGlossaryConceptRowResource;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Ai\Knowledge\AiLayers;
use App\Services\Ai\Knowledge\Concepts\GlossaryConceptRows;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use App\Services\Ai\Knowledge\Concepts\GlossaryTextCheck;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * A glossary as one row per concept. Reads reach glossaries inherited from a team.
 * Writes need the glossary's own owner and create no revision.
 */
class AiGlossaryConceptRowController extends Controller
{
    use ResolvesAiOwner;

    /**
     * Glossary rows with Keep exact, the note, Use, Avoid and Also written as per language, and coverage, sorted by the default language's wording.
     *
     * Search and `missing` look across a row's languages. `meta` also holds `read_only`, `default_locale`, `available_locales` and the whole glossary's `facets`.
     *
     * @apiQuery search string Part of a wording or the note, in any language.
     * @apiQuery missing string Rows with no term for this locale. A base language counts as a term for its regions.
     * @apiQuery page integer
     * @apiQuery per_page integer At most 200.
     *
     * @apiResponse 200 @AiGlossaryConceptRowsPage
     * @apiResponse 404 @Error Unknown glossary, or one of another owner.
     */
    public function index(Request $request, Space|Team|string $owner, string $glossary, GlossaryConceptRows $rows): AnonymousResourceCollection
    {
        $model = $this->readableGlossary($request, $glossary);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'missing' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        ['rows' => $page, 'meta' => $meta] = $rows->list(
            $model,
            $this->viewingSpace($request),
            $filters,
            $this->perPage($request, 50, 200),
            max(1, $request->integer('page', 1)),
            ! $this->owns($request, $model),
        );

        return AiGlossaryConceptRowResource::collection($page)->additional(['meta' => $meta]);
    }

    /**
     * One row by its concept id.
     *
     * @apiResponse 200 data:@AiGlossaryConceptRowResource
     * @apiResponse 404 @Error Unknown row or glossary, or one of another owner.
     */
    public function show(Request $request, Space|Team|string $owner, string $glossary, string $row, GlossaryConceptRows $rows): AiGlossaryConceptRowResource
    {
        $found = $rows->row($this->readableGlossary($request, $glossary), $this->viewingSpace($request), $row);
        abort_if($found === null, 404);

        return new AiGlossaryConceptRowResource($found);
    }

    /**
     * Create a concept with its language terms.
     *
     * Writes live knowledge at once and records no revision. A language with Avoid and no Use is a ban.
     *
     * @apiResponse 201 data:@AiGlossaryConceptRowResource
     * @apiResponse 409 @AiGlossaryConceptConflict `wording_conflict` or `key_conflict`. Nothing changes.
     * @apiResponse 422 @AiGlossaryConceptConflict `term_required`, `use_avoided` when a Use is covered by its own Avoid, `note_too_long` for a note over 200 characters, or a validation error.
     * @apiResponse 403 @Error A glossary inherited from a team is read-only here.
     * @apiResponse 404 @Error Unknown glossary, or one of another owner.
     */
    public function store(SaveAiGlossaryConceptRowRequest $request, Space|Team|string $owner, string $glossary, GlossaryConceptRows $rows): JsonResponse
    {
        $model = $this->writable($request, $glossary);
        $id = GlossaryConcepts::transaction($model, fn (GlossaryConcepts $concepts) => $concepts->create($request->validated()));

        return (new AiGlossaryConceptRowResource($rows->row($model, $this->viewingSpace($request), $id)))->response()->setStatusCode(201);
    }

    /**
     * Change a row by its concept id.
     *
     * Omitted fields and languages keep their value. A language goes only through `remove_languages`. The note and Keep exact hold for the whole row. Send `base` to refuse a write over a value you never saw.
     *
     * @apiResponse 200 data:@AiGlossaryConceptRowResource
     * @apiResponse 409 @AiGlossaryConceptConflict `wording_conflict`, `key_conflict`, or `row_changed` when `base` is out of date. Nothing changes.
     * @apiResponse 422 @AiGlossaryConceptConflict `term_required`, `use_avoided` when a Use is covered by its own Avoid, `last_language`, `note_too_long` for a new or changed note over 200 characters, or a validation error.
     * @apiResponse 403 @Error A glossary inherited from a team is read-only here.
     * @apiResponse 404 @Error Unknown row or glossary, or one of another owner.
     */
    public function update(SaveAiGlossaryConceptRowRequest $request, Space|Team|string $owner, string $glossary, string $row, GlossaryConceptRows $rows): AiGlossaryConceptRowResource
    {
        $model = $this->writable($request, $glossary);
        $id = GlossaryConcepts::transaction($model, fn (GlossaryConcepts $concepts) => $concepts->update($row, $request->validated()));

        return new AiGlossaryConceptRowResource($rows->row($model, $this->viewingSpace($request), $id));
    }

    /**
     * Delete a row by its concept id, with all its languages.
     *
     * @apiResponse 403 @Error A glossary inherited from a team is read-only here.
     * @apiResponse 404 @Error Unknown row or glossary, or one of another owner.
     */
    public function destroy(Request $request, Space|Team|string $owner, string $glossary, string $row): JsonResponse
    {
        $model = $this->writable($request, $glossary);
        GlossaryConcepts::transaction($model, fn (GlossaryConcepts $concepts) => $concepts->remove($row));

        return response()->json(null, 204);
    }

    /**
     * Delete selected rows in one transaction. An unknown row refuses the whole request.
     *
     * @apiResponse 200 data:@AiGlossaryConceptRowsBulkResult
     * @apiResponse 422 @ValidationError Under `row_ids` for rows this glossary does not have.
     * @apiResponse 403 @Error A glossary inherited from a team is read-only here.
     * @apiResponse 404 @Error Unknown glossary, or one of another owner.
     */
    public function bulk(BulkAiGlossaryConceptRowsRequest $request, Space|Team|string $owner, string $glossary): JsonResponse
    {
        $model = $this->writable($request, $glossary);
        $ids = array_values(array_unique($request->validated('row_ids')));

        $count = GlossaryConcepts::transaction($model, function (GlossaryConcepts $concepts) use ($ids) {
            $unknown = array_values(array_filter($ids, fn (string $id) => ! $concepts->hasRow($id)));

            if ($unknown !== []) {
                throw ValidationException::withMessages(['row_ids' => ['These rows do not exist in this glossary: '.implode(', ', $unknown).'.']]);
            }

            foreach ($ids as $id) {
                $concepts->remove($id);
            }

            return \count($ids);
        });

        return response()->json(['data' => ['deleted' => $count]]);
    }

    /**
     * Try a text against saved wording. Makes no provider call and changes nothing.
     *
     * With `scope` set to `glossary`, the default, this reads this one glossary, which need not be what a run uses. With `space` it reads what a run in this space would use: every enabled glossary, inherited ones included, nearest first. Returns the rows the text activates, with match offsets in UTF-16 code units and the instruction the agent would read, and findings if `output` is sent.
     *
     * @apiResponse 200 data:@AiGlossaryCheckResult
     * @apiResponse 404 @Error Unknown glossary, or one of another owner.
     */
    public function check(CheckAiGlossaryTextRequest $request, Space|Team|string $owner, string $glossary, GlossaryTextCheck $check, KnowledgeResolver $knowledge): JsonResponse
    {
        $model = $this->readableGlossary($request, $glossary);
        $args = [$request->validated('text'), $request->validated('source_locale'), $request->validated('target_locale'), $request->validated('output')];

        if ($request->validated('scope') !== 'space') {
            return response()->json(['data' => $check->forGlossary($model, ...$args)]);
        }

        $space = $this->viewingSpace($request);
        abort_if($space === null, 422, 'A team has no runs of its own. Try the text from a space.');

        return response()->json(['data' => $check->run($knowledge->livePolicy($space), ...$args)]);
    }

    private function writable(Request $request, string $id): AiGlossary
    {
        $owner = $this->aiOwner($request);
        $this->authorize('manage', [AiGlossary::class, $owner]);

        $glossary = AiGlossary::query()
            ->inLayers($owner instanceof Space ? $owner->id : null, app(AiLayers::class)->teamIds($owner))
            ->findOrFail($id);
        abort_unless($this->owns($request, $glossary), 403, 'A glossary inherited from a team is read-only here.');

        return $glossary;
    }

    private function owns(Request $request, AiGlossary $glossary): bool
    {
        $owner = $this->aiOwner($request);

        return $owner instanceof Space ? $glossary->space_id === $owner->id : $glossary->team_id === $owner->id;
    }

    /** Languages come from the space looking at the glossary, also for an inherited one. */
    private function viewingSpace(Request $request): ?Space
    {
        $owner = $this->aiOwner($request);

        return $owner instanceof Space ? $owner : null;
    }
}
