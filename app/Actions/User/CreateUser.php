<?php

namespace App\Actions\User;

use App\Enums\MembershipSource;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\MembershipService;

class CreateUser
{
    public function __construct(
        private readonly MembershipService $membershipService,
    ) {}

    public function execute(array $data): User
    {
        $user = User::create($data);
        $team = Team::create([
            'name' => $data['firstname'].' '.$data['lastname'],
            'description' => __('labels.teams.personalTeamDescription'),
            'icon' => 'user',
            'type' => 'personal',
        ]);
        $this->membershipService->assignTeamRole($team, $user, 'owner', MembershipSource::Owner);

        return $user;
    }
}
