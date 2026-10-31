<?php

namespace App\Listeners\User;

use App\Models\User;
use App\Services\Team\TeamDomainService;
use Illuminate\Auth\Events\Verified;

/** A freshly verified address may now auto-join its domain's team. */
class JoinTeamThroughDomain
{
    public function __construct(private readonly TeamDomainService $domains) {}

    public function handle(Verified $event): void
    {
        if ($event->user instanceof User) {
            $this->domains->joinThroughDomain($event->user);
        }
    }
}
