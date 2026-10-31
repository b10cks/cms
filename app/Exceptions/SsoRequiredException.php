<?php

namespace App\Exceptions;

use App\Models\Management\Team;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The email's domain is verified by a team that requires its SSO. Renders the
 * team's SAML login URL so the client can send the user there.
 */
class SsoRequiredException extends RuntimeException
{
    public function __construct(public readonly Team $team)
    {
        parent::__construct(__('auth.sso_required', ['team' => $team->name]));
    }

    public function loginUrl(): string
    {
        return route('auth.saml.redirect', ['team' => $this->team]);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => 'SSO_REQUIRED',
            'team' => ['id' => $this->team->id, 'name' => $this->team->name],
            'sso_url' => $this->loginUrl(),
        ], 403);
    }
}
