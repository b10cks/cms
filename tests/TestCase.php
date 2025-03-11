<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected User $user;

    protected function createAndActAs(?User $user = null): void
    {
        if (!$user) {
            $user = User::factory()->create();
        }
        $this->user = $user;
        $this->actingAs($this->user);
    }
}
