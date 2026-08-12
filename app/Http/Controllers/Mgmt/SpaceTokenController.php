<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Space\CreateToken;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\TokenFilter;
use App\Http\Requests\Token\CreateTokenRequest;
use App\Http\Resources\Management\TokenResource;
use App\Models\Management\Space;
use App\Models\Management\Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SpaceTokenController extends Controller
{
    /**
     * Display a listing of the tokens for a space.
     */
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorize('viewAny', [Token::class, $space]);

        $tokens = Token::where('space_id', $space->id)
            ->filter(TokenFilter::fromRequest($request))
            ->paginate();

        return TokenResource::collection($tokens);
    }

    /**
     * Store a newly created token in storage.
     */
    public function store(CreateTokenRequest $request, Space $space, CreateToken $action): JsonResponse
    {
        $this->authorize('create', [Token::class, $space]);

        $result = $action->execute($request->validated(), $space, auth()->user());

        return response()->json([
            'token' => new TokenResource($result['token']),
            'plain_text_token' => $result['plain_text_token']
        ], 201);
    }

    /**
     * Remove the specified token from storage.
     */
    public function destroy(Space $space, Token $token): JsonResponse
    {
        $this->authorize('delete', [$token, $space]);

        $token->delete();

        return response()->json(null, 204);
    }
}
