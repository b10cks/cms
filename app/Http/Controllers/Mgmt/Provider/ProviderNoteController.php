<?php

namespace App\Http\Controllers\Mgmt\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\StoreProviderNoteRequest;
use App\Http\Requests\Provider\UpdateProviderNoteRequest;
use App\Http\Resources\Management\ProviderNoteResource;
use App\Models\Management\ProviderNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;

class ProviderNoteController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        // Deliberately readable by every authenticated user: these are the
        // operator's announcements, rendered in the sidebar for everyone.
        // Writing them stays root-only, gated in the form requests.
        $notes = ProviderNote::query()
            ->orderByDesc('is_pinned')
            ->latest('updated_at')
            ->paginate(min((int) $request->integer('per_page', 50), 50));

        return ProviderNoteResource::collection($notes);
    }

    public function store(StoreProviderNoteRequest $request): ProviderNoteResource
    {
        $note = ProviderNote::query()->create($request->validated());

        return new ProviderNoteResource($note);
    }

    public function update(UpdateProviderNoteRequest $request, ProviderNote $providerNote): ProviderNoteResource
    {
        $providerNote->fill($request->validated());
        $providerNote->save();

        return new ProviderNoteResource($providerNote);
    }

    public function destroy(ProviderNote $providerNote): Response
    {
        abort_unless((bool) auth()->user()?->is_root, 403);

        $providerNote->delete();

        return response()->noContent();
    }
}
