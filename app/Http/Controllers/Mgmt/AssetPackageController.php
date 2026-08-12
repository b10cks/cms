<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\AssetPackageResource;
use App\Models\Management\Space;
use App\Models\Space\AssetPackage;
use App\Services\Asset\AssetPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AssetPackageController extends Controller
{
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorizeSpace($space, 'assets.view');

        $packages = AssetPackage::query()
            ->with('creator')
            ->orderByDesc('created_at')
            ->paginate();

        return AssetPackageResource::collection($packages);
    }

    public function store(Space $space, Request $request, AssetPackageService $service): JsonResponse
    {
        $this->authorizeSpace($space, 'assets.manage');

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'source_type' => ['required', Rule::in(['collection', 'selection', 'folder'])],
            'collection_id' => ['required_if:source_type,collection', 'nullable', 'string', 'max:26'],
            'folder_id' => ['required_if:source_type,folder', 'nullable', 'string', 'max:26'],
            'asset_ids' => ['required_if:source_type,selection', 'nullable', 'array', 'min:1', 'max:1000'],
            'asset_ids.*' => ['string', 'max:26'],
        ]);

        $package = $service->createPackage($space, $validated, auth()->user());

        return (new AssetPackageResource($package))
            ->response($request)
            ->setStatusCode(202);
    }

    public function show(Space $space, AssetPackage $package): AssetPackageResource
    {
        $this->authorizeSpace($space, 'assets.view');

        return new AssetPackageResource($package->load('creator'));
    }

    /**
     * Internal (management UI) download: an un-metered presigned S3 URL —
     * the metered CloudFront path is reserved for public share downloads.
     */
    public function download(Space $space, AssetPackage $package): JsonResponse
    {
        $this->authorizeSpace($space, 'assets.view');

        if (! $package->isCompleted() || empty($package->s3_path)) {
            return response()->json([
                'message' => 'The package is not ready for download.',
                'state' => $package->state,
                'progress' => $package->progress,
            ], 409);
        }

        $minutes = (int) config('asset_distribution.download_url_ttl_minutes', 15);

        return response()->json([
            'url' => $package->getDownloadUrl($minutes),
            'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
        ]);
    }

    public function destroy(Space $space, AssetPackage $package): JsonResponse
    {
        $this->authorizeSpace($space, 'assets.manage');

        try {
            if ($package->s3_path) {
                $disk = Storage::disk('transfers');

                if ($disk->exists($package->s3_path)) {
                    $disk->delete($package->s3_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to delete package archive from transfers disk', [
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);
        }

        $package->delete();

        return response()->json(null, 204);
    }
}
