<?php

namespace Tests\Traits;

use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;

trait SpaceTestingTrait
{
    protected function runSpaceMigrations(): void
    {
        $this->artisan('migrate', [
            '--path' => 'database/migrations/spaces',
            '--realpath' => true,
        ]);
    }

    protected function setUpSpaceTesting(Space $space): void
    {
        SpaceConnection::forceCreate([
            'name' => 'internal',
            'space_id' => $space->id,
            'driver' => 'sqlite',
            'config' => [
                'database' => ':memory:',
            ],
        ]);
        $this->runSpaceMigrations();
        app('router')->getCurrentRoute()?->setParameter('space', $space);
    }
}
