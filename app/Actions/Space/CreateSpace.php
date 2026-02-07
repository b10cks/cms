<?php

namespace App\Actions\Space;

use App\Jobs\Space\SetupSpace;
use App\Models\Management\Space;
use App\Models\Management\SpaceBlueprint;
use App\Models\User;
use App\Services\Auth\MembershipService;
use Illuminate\Contracts\Auth\Authenticatable;

class CreateSpace
{
    public function __construct(
        private readonly MembershipService $membershipService,
    ) {
    }

    public function execute(array $data, Authenticatable|User|null $owner): Space
    {
        $data['state'] = 'draft';

        $blueprintId = $data['blueprint_id'] ?? null;
        unset($data['blueprint_id']);

        $blueprintSettings = [];
        if ($blueprintId) {
            $blueprint = SpaceBlueprint::findOrFail($blueprintId);
            $blueprintSettings = $blueprint->settings ?? [];
        }

        $data['settings'] = array_replace_recursive($blueprintSettings, $data['settings'] ?? []) + [
            'asset_fields' => [
                ['key' => 'alt', 'label' => 'Alt Text', 'required' => false],
                ['key' => 'description', 'label' => 'Description', 'required' => false],
            ],
        ];

        $space = Space::forceCreate($data);

        if ($owner) {
            $this->membershipService->assignSpaceRole($space, $owner, 'owner');
        }

        SetupSpace::dispatchSync($space, $blueprintId);

        return $space;
    }
}
