<?php

namespace Database\Factories\System;


use App\Models\Management\SpaceConnection;
use App\Models\System\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => $this->faker->randomElement(['created', 'updated', 'deleted']),
            'entity_type' => SpaceConnection::class,
            'entity_id' => SpaceConnection::factory(),
            'old_values' => null,
            'new_values' => ['state' => 'live'],
            'metadata' => [
                'ip' => $this->faker->ipv4,
                'user_agent' => $this->faker->userAgent
            ]
        ];
    }
}
