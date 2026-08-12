<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\AssetShareResource;
use App\Models\Management\Space;
use App\Models\Space\AssetShare;
use App\Services\Asset\AssetPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssetShareController extends Controller
{
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorizeSpace($space, 'asset_shares.view');

        $filters = $request->validate([
            'source_type' => ['sometimes', Rule::in(['collection', 'selection', 'folder'])],
            'collection_id' => ['sometimes', 'string', 'max:26'],
            'folder_id' => ['sometimes', 'string', 'max:26'],
        ]);

        $shares = AssetShare::query()
            ->with(['creator', 'package'])
            ->when($filters['source_type'] ?? null, fn ($query, $type) => $query->where('source_type', $type))
            ->when($filters['collection_id'] ?? null, fn ($query, $id) => $query->where('collection_id', $id))
            ->when($filters['folder_id'] ?? null, fn ($query, $id) => $query->where('folder_id', $id))
            ->orderByDesc('created_at')
            ->paginate();

        return AssetShareResource::collection($shares);
    }

    public function store(Space $space, Request $request, AssetPackageService $packageService): AssetShareResource
    {
        $this->authorizeSpace($space, 'asset_shares.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'source_type' => ['required', Rule::in(['collection', 'selection', 'folder'])],
            'collection_id' => ['required_if:source_type,collection', 'nullable', 'string', 'max:26'],
            'folder_id' => ['required_if:source_type,folder', 'nullable', 'string', 'max:26'],
            'asset_ids' => ['required_if:source_type,selection', 'nullable', 'array', 'min:1', 'max:1000'],
            'asset_ids.*' => ['string', 'max:26'],
            'password' => ['nullable', 'string', 'min:4', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'download_limit' => ['nullable', 'integer', 'min:1'],
            'allow_individual_downloads' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array'],
        ]);

        $share = AssetShare::create([
            ...$validated,
            'password' => ! empty($validated['password']) ? Hash::make($validated['password']) : null,
            'token' => AssetShare::generateToken(),
            'created_by_id' => auth()->id(),
        ]);

        // Eagerly build the zip so the first public download doesn't have to
        // wait for a cold build.
        $packageService->ensureFreshPackageForShare($space, $share);

        return new AssetShareResource($share->load('package'));
    }

    public function show(Space $space, AssetShare $share): AssetShareResource
    {
        $this->authorizeSpace($space, 'asset_shares.view');

        return new AssetShareResource($share->load(['creator', 'package']));
    }

    /**
     * Password semantics: key absent = keep current password, explicit null
     * (or empty string) = remove the password, non-empty string = re-hash.
     */
    public function update(Space $space, Request $request, AssetShare $share): AssetShareResource
    {
        $this->authorizeSpace($space, 'asset_shares.manage');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'source_type' => ['sometimes', Rule::in(['collection', 'selection', 'folder'])],
            'collection_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'asset_ids' => ['sometimes', 'nullable', 'array', 'min:1', 'max:1000'],
            'asset_ids.*' => ['string', 'max:26'],
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'download_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'allow_individual_downloads' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);

        if (array_key_exists('password', $validated)) {
            $validated['password'] = ($validated['password'] !== null && $validated['password'] !== '')
                ? Hash::make($validated['password'])
                : null;
        }

        $share->fill($validated);

        // store() enforces the source pairs via required_if; on partial
        // updates the pairing must hold for the *resulting* attributes.
        $this->validateSourceDefinition($share);

        // A changed source definition invalidates the built package; detach it
        // so the next download triggers a rebuild against the new source.
        if ($share->isDirty(['source_type', 'collection_id', 'folder_id', 'asset_ids'])) {
            $share->package_id = null;
        }

        $share->save();

        return new AssetShareResource($share->load(['creator', 'package']));
    }

    private function validateSourceDefinition(AssetShare $share): void
    {
        $problem = match ($share->source_type) {
            'collection' => $share->collection_id ? null : 'The collection source requires a collection_id.',
            'folder' => $share->folder_id ? null : 'The folder source requires a folder_id.',
            'selection' => ! empty($share->asset_ids) ? null : 'The selection source requires asset_ids.',
            default => 'Unknown source type.',
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['source_type' => $problem]);
        }
    }

    public function revoke(Space $space, AssetShare $share): AssetShareResource
    {
        $this->authorizeSpace($space, 'asset_shares.manage');

        if (! $share->isRevoked()) {
            $share->forceFill(['revoked_at' => now()])->save();
        }

        return new AssetShareResource($share->load('package'));
    }

    public function destroy(Space $space, AssetShare $share): JsonResponse
    {
        $this->authorizeSpace($space, 'asset_shares.manage');

        $share->delete();

        return response()->json(null, 204);
    }
}
