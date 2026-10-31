<?php

namespace App\Services\Team;

use App\Enums\MembershipSource;
use App\Enums\TeamDomainPolicy;
use App\Models\Management\Team;
use App\Models\Management\TeamDomain;
use App\Models\User;
use App\Services\Auth\MembershipService;
use App\Services\System\AuditService;
use App\Support\EmailDomain;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Verified email domains: DNS verification, and what a matching email means
 * at sign-in (request to join, auto-join, or SSO only). A domain name is
 * verified by at most one team instance-wide, so an address maps to one team.
 */
class TeamDomainService
{
    public function __construct(
        private readonly DnsTxtLookup $dns,
        private readonly MembershipService $membershipService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Checks the TXT record and marks the domain verified. Returns false when
     * the record is not (yet) published.
     */
    public function verify(TeamDomain $domain): bool
    {
        if ($domain->isVerified()) {
            return true;
        }

        $expected = $domain->txtRecord();
        $found = collect($this->dns->records($domain->domain))
            ->contains(fn (string $record) => hash_equals($expected, trim($record, " \t\"")));

        if (! $found) {
            return false;
        }

        $this->ensureNotVerifiedElsewhere($domain);

        try {
            $domain->forceFill(['verified_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            // Lost a race against another team verifying the same name.
            $this->denyTaken();
        }

        $this->audit('domain_verified', $domain);

        return true;
    }

    public function ensureNotVerifiedElsewhere(TeamDomain $domain): void
    {
        $taken = TeamDomain::query()
            ->where('domain', $domain->domain)
            ->whereNotNull('verified_at')
            ->whereKeyNot($domain->getKey())
            ->exists();

        if ($taken) {
            $this->denyTaken();
        }
    }

    public function verifiedDomainForEmail(string $email): ?TeamDomain
    {
        $domain = EmailDomain::fromEmail($email);
        if ($domain === null) {
            return null;
        }

        return TeamDomain::query()
            ->with('team')
            ->where('domain', $domain)
            ->whereNotNull('verified_at')
            ->whereHas('team')
            ->first();
    }

    /**
     * The team whose SSO an address must use, or null when any sign-in is
     * fine. Applies only while that team has an enabled SAML provider, so a
     * disabled IdP cannot lock its people out. Root accounts are exempt.
     */
    public function ssoRequiredTeam(string $email, ?User $user = null): ?Team
    {
        if ($user?->is_root) {
            return null;
        }

        $domain = $this->verifiedDomainForEmail($email);
        if ($domain?->policy !== TeamDomainPolicy::RequireSso) {
            return null;
        }

        $hasSso = $domain->team->samlProvider()->where('enabled', true)->exists();

        return $hasSso ? $domain->team : null;
    }

    /**
     * Whether a team's IdP may vouch for this address because the team
     * verified its domain. Owning the domain's DNS means owning its mail.
     */
    public function teamOwnsEmailDomain(string $teamId, string $email): bool
    {
        return $this->verifiedDomainForEmail($email)?->team_id === $teamId;
    }

    /**
     * Runs after every verified sign-in: joins the user to the team of an
     * auto-join domain with its default role. A no-op for anyone else.
     */
    public function joinThroughDomain(User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            return;
        }

        $domain = $this->verifiedDomainForEmail($user->email);
        if ($domain?->policy !== TeamDomainPolicy::AutoJoin || $this->isMember($domain->team, $user)) {
            return;
        }

        $this->membershipService->assignTeamRole(
            $domain->team,
            $user,
            $domain->defaultRoleKey(),
            MembershipSource::Domain,
        );
    }

    /**
     * Teams the user may ask to join through their verified email domain,
     * each with the user's own request on it, if any.
     *
     * @return Collection<int, TeamDomain>
     */
    public function joinableDomains(User $user): Collection
    {
        $domain = $user->hasVerifiedEmail() ? $this->verifiedDomainForEmail($user->email) : null;

        if (
            $domain === null
            || $domain->policy !== TeamDomainPolicy::Request
            || $this->isMember($domain->team, $user)
        ) {
            return new Collection;
        }

        $domain->setRelation(
            'joinRequest',
            $domain->team->joinRequests()->where('user_id', $user->id)->first(),
        );

        return new Collection([$domain]);
    }

    public function isMember(Team $team, User $user): bool
    {
        return $team->users()->where('users.id', $user->id)->exists();
    }

    /**
     * Writes team-level audit entries for domain and join-request events, next
     * to the membership entries MembershipService writes.
     */
    public function audit(string $action, TeamDomain $domain, ?array $old = null, ?array $new = null, array $metadata = []): void
    {
        $this->auditService->log(
            action: $action,
            entity: $domain->team,
            oldValues: $old,
            newValues: $new,
            metadata: ['domain_id' => $domain->id, 'domain' => $domain->domain] + $metadata,
        );
    }

    private function denyTaken(): never
    {
        throw ValidationException::withMessages([
            'domain' => [__('This domain is already verified by another team.')],
        ]);
    }
}
