<?php

namespace App\Http\Controllers\Mgmt;

use App\Enums\TeamDomainPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Team\StoreTeamDomainRequest;
use App\Http\Requests\Team\UpdateTeamDomainRequest;
use App\Http\Resources\Management\TeamDomainResource;
use App\Models\Management\Team;
use App\Models\Management\TeamDomain;
use App\Services\Auth\RoleService;
use App\Services\Team\TeamDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamDomainController extends Controller
{
    public function index(Team $team): AnonymousResourceCollection
    {
        $this->authorize('manageDomains', $team);

        return TeamDomainResource::collection(
            $team->domains()->with('defaultRole')->orderBy('domain')->get()
        );
    }

    public function store(StoreTeamDomainRequest $request, Team $team, TeamDomainService $domains): JsonResponse
    {
        $this->authorize('manageDomains', $team);

        /** @var TeamDomain $domain */
        $domain = $team->domains()->create([
            'domain' => $request->normalizedDomain(),
            'verification_token' => Str::lower(Str::random(32)),
        ]);

        $domains->audit('domain_added', $domain->setRelation('team', $team));

        return (new TeamDomainResource($domain->refresh()))->response()->setStatusCode(201);
    }

    public function verify(Team $team, TeamDomain $domain, TeamDomainService $domains): TeamDomainResource
    {
        $this->authorize('manageDomains', $team);
        $this->ensureBelongs($team, $domain);

        if (! $domains->verify($domain->setRelation('team', $team))) {
            throw ValidationException::withMessages([
                'domain' => [__('The TXT record was not found yet. DNS changes can take a while to show up.')],
            ]);
        }

        return new TeamDomainResource($domain->refresh());
    }

    public function update(
        UpdateTeamDomainRequest $request,
        Team $team,
        TeamDomain $domain,
        TeamDomainService $domains,
        RoleService $roles,
    ): TeamDomainResource {
        $this->authorize('manageDomains', $team);
        $this->ensureBelongs($team, $domain);

        $before = ['policy' => $domain->policy->value, 'default_role' => $domain->defaultRoleKey()];

        if ($request->has('policy')) {
            $domain->policy = $request->enum('policy', TeamDomainPolicy::class);
        }

        if ($request->has('default_role')) {
            $domain->default_role_id = $roles->resolveTeamRole($request->string('default_role')->toString())->id;
        }

        $domain->save();
        $domain->load('defaultRole');

        $after = ['policy' => $domain->policy->value, 'default_role' => $domain->defaultRoleKey()];
        if ($before !== $after) {
            $domains->audit('domain_policy_changed', $domain->setRelation('team', $team), $before, $after);
        }

        return new TeamDomainResource($domain);
    }

    public function destroy(Team $team, TeamDomain $domain, TeamDomainService $domains): Response
    {
        $this->authorize('manageDomains', $team);
        $this->ensureBelongs($team, $domain);

        $domain->delete();
        $domains->audit('domain_removed', $domain->setRelation('team', $team));

        return response()->noContent();
    }

    private function ensureBelongs(Team $team, TeamDomain $domain): void
    {
        abort_unless($domain->team_id === $team->id, 404);
    }
}
