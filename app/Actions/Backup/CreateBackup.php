<?php

namespace App\Actions\Backup;

use App\Jobs\Space\CreateBackup as CreateBackupJob;
use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use App\Models\User;

class CreateBackup
{
    public function execute(array $data, Space $space, User $creator): SpaceBackup
    {
        $backup = SpaceBackup::create([
            'space_id' => $space->id,
            'created_by' => $creator->id,
            'state' => 'pending',
            'progress' => 0,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'recipients' => $data['to'],
            'password' => $data['password'] ?? null,
            'expires_at' => $data['expires_at'],
        ]);

        CreateBackupJob::dispatch($backup, $space);

        return $backup;
    }
}
