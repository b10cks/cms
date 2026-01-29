<?php

namespace App\Actions\User;

use App\Models\User;
use App\Models\Management\Team;

class CreateUser
{
    public function execute(array $data): User
    {
        $user = User::create($data);
        $team = Team::create([
            'name' => $data['firstname'] . ' ' . $data['lastname'],
            'description' => __('labels.teams.personalTeamDescription'),
            'icon' => 'user',
            'type' => 'personal'
        ]);
        $team->users()
            ->attach($user, ['role' => 'owner']);

        return $user;
    }
}
