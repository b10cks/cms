<?php

namespace Database\Seeders;

use App\Services\Auth\SystemRoleSynchronizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    public function run(SystemRoleSynchronizer $synchronizer): void
    {
        if (! DB::getSchemaBuilder()->hasTable('roles')) {
            return;
        }

        $synchronizer->sync();
    }
}
