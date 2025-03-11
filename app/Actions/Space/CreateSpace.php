<?php

namespace App\Actions\Space;

use App\Jobs\Space\SetupSpace;
use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class CreateSpace
{
    public function execute(array $data, Authenticatable|User|null $owner): Space
    {
        $data['state'] = 'draft';

        $data['settings'] = ($data['settings'] ?? []) + [
                'asset_fields' => [
                    ['key' => 'alt', 'label' => 'Alt Text', 'required' => false],
                    ['key' => 'description', 'label' => 'Description', 'required' => false],
                ]
            ];

        $space = Space::forceCreate($data);
        $space->users()->attach($owner, ['role' => 'owner']);

        SetupSpace::dispatch($space);

        return $space;
    }
}
