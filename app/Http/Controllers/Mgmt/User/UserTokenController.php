<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreatePersonalAccessTokenRequest;
use App\Http\Requests\User\ListPersonalAccessTokenRequest;
use App\Http\Resources\User\PersonalAccessTokenListResource;
use App\Http\Resources\User\PersonalAccessTokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Http\Resources\Json\ResourceCollection;

class UserTokenController extends Controller
{
    public function index(ListPersonalAccessTokenRequest $request): ResourceCollection
    {
        $this->authorize('viewAny', PersonalAccessToken::class);

        $perPage = $request->integer('per_page', 20);
        $tokens = $request->user()
            ->tokens()
            ->latest()
            ->paginate($perPage);

        return PersonalAccessTokenListResource::collection($tokens);
    }

    public function store(CreatePersonalAccessTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $abilities = $this->resolveAbilities($request->input('abilities'));
        $expiresAt = $request->filled('expires_at')
            ? Carbon::parse($request->input('expires_at'))
            : null;

        $token = $user->createToken($request->input('name'), $abilities, $expiresAt);

        return response()->json([
            'token' => new PersonalAccessTokenResource($token->accessToken),
            'plain_text_token' => $token->plainTextToken,
        ], 201);
    }

    public function destroy(PersonalAccessToken $token): JsonResponse
    {
        $this->authorize('delete', $token);

        $token->delete();

        return response()->json(null, 204);
    }

    private function resolveAbilities(?array $abilities): array
    {
        $abilities = array_values(array_filter($abilities ?? [], fn($ability) => $ability !== null && $ability !== ''));

        return $abilities ?: ['*'];
    }
}
