<?php

namespace App\Actions\Space;

use App\Jobs\Space\SetupSpace;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Auth\MembershipService;
use Illuminate\Contracts\Auth\Authenticatable;

class CreateSpace
{
    public function __construct(
        private readonly MembershipService $membershipService,
    ) {}

    public function execute(array $data, Authenticatable|User|null $owner): Space
    {
        $data['state'] = 'draft';

        $data['settings'] = ($data['settings'] ?? []) + [
            'asset_fields' => [
                ['key' => 'alt', 'label' => 'Alt Text', 'required' => false],
                ['key' => 'description', 'label' => 'Description', 'required' => false],
            ],
        ];

        $space = Space::forceCreate($data);

        if ($owner) {
            $this->membershipService->assignSpaceRole($space, $owner, 'owner');
        }

        SetupSpace::dispatchSync($space);

        return $space;
    }
}
