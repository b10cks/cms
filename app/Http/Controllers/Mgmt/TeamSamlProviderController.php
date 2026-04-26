<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\UpsertTeamSamlProviderRequest;
use App\Http\Resources\Management\TeamSamlProviderResource;
use App\Models\Management\Team;
use App\Models\Management\TeamSamlProvider;
use App\Services\Auth\SamlLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class TeamSamlProviderController extends Controller
{
    public function show(Team $team): JsonResponse
    {
        $this->authorize('manageSaml', $team);

        $provider = $team->samlProvider()->first();

        return response()->json([
            'data' => $provider ? new TeamSamlProviderResource($provider) : null,
            'defaults' => $this->defaults($team),
        ]);
    }

    public function upsert(UpsertTeamSamlProviderRequest $request, Team $team): JsonResponse
    {
        $this->authorize('manageSaml', $team);

        $provider = $team->samlProvider()->first();
        $data = $request->validated();

        if (array_key_exists('sp_private_key', $data) && blank($data['sp_private_key']) && $provider) {
            unset($data['sp_private_key']);
        }

        /** @var TeamSamlProvider $provider */
        $provider = $team->samlProvider()->updateOrCreate(
            ['team_id' => $team->id],
            $data + ['team_id' => $team->id],
        );

        return (new TeamSamlProviderResource($provider->refresh()))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(Team $team): Response
    {
        $this->authorize('manageSaml', $team);

        $team->samlProvider()->delete();

        return response()->noContent();
    }

    private function defaults(Team $team): array
    {
        return [
            'enabled' => false,
            'idp_entity_id' => '',
            'sso_url' => '',
            'slo_url' => null,
            'idp_x509_cert' => '',
            'sp_x509_cert' => null,
            'name_id_format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'attribute_mapping' => [
                'email' => 'email',
                'first_name' => 'firstName',
                'last_name' => 'lastName',
                'external_id' => SamlLoginService::NAME_ID_MAPPING,
            ],
            'role_attribute' => null,
            'role_mapping' => [],
            'default_role' => 'member',
            'allow_jit' => true,
            'strict' => true,
            'sign_authn_requests' => false,
            'sign_logout_requests' => false,
            'want_assertions_signed' => true,
            'want_messages_signed' => false,
            'want_assertions_encrypted' => false,
            'digest_algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            'signature_algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            'links' => [
                'login_url' => route('auth.saml.redirect', ['team' => $team]),
                'acs_url' => route('auth.saml.acs', ['team' => $team]),
                'sls_url' => route('auth.saml.sls', ['team' => $team]),
                'metadata_url' => route('auth.saml.metadata', ['team' => $team]),
                'sp_entity_id' => route('auth.saml.metadata', ['team' => $team]),
            ],
        ];
    }
}
