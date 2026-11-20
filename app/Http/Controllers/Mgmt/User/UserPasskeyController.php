<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserPasskeyResource;
use App\Models\User\UserPasskey;
use App\Notifications\User\PasskeyAddedNotification;
use App\Notifications\User\PasskeyRemovedNotification;
use App\Services\Auth\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserPasskeyController extends Controller
{
    /** List the passkeys of the current user. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return UserPasskeyResource::collection($request->user()->passkeys()->latest()->get());
    }

    /**
     * Start adding a passkey. Returns WebAuthn creation options; the route
     * asks for the TOTP code or password first.
     */
    public function options(Request $request, PasskeyService $passkeys): JsonResponse
    {
        return response()->json($passkeys->creationOptions($request, $request->user()));
    }

    /** Finish adding a passkey with the browser's answer to the creation options. */
    public function store(Request $request, PasskeyService $passkeys): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'credential' => ['required', 'array'],
        ]);

        $user = $request->user();
        $passkey = $passkeys->register($request, $user, $validated['credential'], $validated['name']);

        $user->notify(new PasskeyAddedNotification($passkey->name));

        return (new UserPasskeyResource($passkey))->response()->setStatusCode(201);
    }

    /** Rename a passkey of the current user. */
    public function update(Request $request, string $passkey): UserPasskeyResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $model = $this->find($request, $passkey);
        $model->update(['name' => $validated['name']]);

        return new UserPasskeyResource($model);
    }

    /** Remove a passkey of the current user. The route asks for a step-up first. */
    public function destroy(Request $request, string $passkey): JsonResponse
    {
        $model = $this->find($request, $passkey);
        $model->delete();

        $request->user()->notify(new PasskeyRemovedNotification($model->name));

        return response()->json(null, 204);
    }

    private function find(Request $request, string $id): UserPasskey
    {
        return $request->user()->passkeys()->findOrFail($id);
    }
}
