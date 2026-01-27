<?php

namespace App\Actions\Release;

use App\Models\Space\Release;
use App\Models\User;

class CreateRelease
{
    public function execute(array $data, Release $release, ?User $owner = null): Release
    {
        $release->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'settings' => $data['settings'] ?? [],
            'publish_at' => $data['publish_at'],
            'external_id' => $data['external_id'] ?? null,
        ]);
        $release->owner_id = $owner?->id;

        $release->save();

        return $release;
    }
}
