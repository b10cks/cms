<?php

namespace App\Actions\Migration;

use App\Jobs\Space\RunSpaceMigration;
use App\Models\Management\Space;
use App\Models\Management\SpaceMigration;
use App\Models\User;

class CreateMigration
{
    public function execute(array $data, Space $sourceSpace, User $creator): SpaceMigration
    {
        $migration = SpaceMigration::create([
            'source_space_id' => $sourceSpace->id,
            'target_space_id' => $data['target_space_id'],
            'created_by' => $creator->id,
            'state' => 'pending',
            'progress' => 0,
            'scope' => $data['scope'],
            'conflict_strategy' => $data['conflict_strategy'],
        ]);

        RunSpaceMigration::dispatch($migration);

        return $migration;
    }
}
