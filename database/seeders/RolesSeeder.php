<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('roles')) {
            return;
        }

        foreach (config('authorization.roles') as $scope => $roles) {
            foreach ($roles as $key => $definition) {
                DB::table('roles')->updateOrInsert(
                    [
                        'scope' => $scope,
                        'team_id' => null,
                        'key' => $key,
                    ],
                    [
                        'id' => DB::table('roles')
                            ->where('scope', $scope)
                            ->whereNull('team_id')
                            ->where('key', $key)
                            ->value('id') ?? (string) Str::ulid(),
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'level' => $definition['level'],
                        'is_system' => true,
                        'abilities' => json_encode($definition['abilities'], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
