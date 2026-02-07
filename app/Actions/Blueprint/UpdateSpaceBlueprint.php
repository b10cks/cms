<?php

namespace App\Actions\Blueprint;

use App\Models\Management\SpaceBlueprint;

class UpdateSpaceBlueprint
{
    public function execute(SpaceBlueprint $blueprint, array $data): SpaceBlueprint
    {
        $blueprint->fill($data);
        $blueprint->save();

        return $blueprint->fresh();
    }
}
