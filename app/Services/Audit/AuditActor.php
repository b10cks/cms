<?php

namespace App\Services\Audit;

use App\Models\User;

final class AuditActor
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?string $systemKey,
    ) {}

    public static function user(User $user): self
    {
        return new self(
            type: 'user',
            id: $user->id,
            name: $user->display_name ?? $user->email,
            systemKey: null,
        );
    }

    public static function system(string $key, string $label): self
    {
        return new self(
            type: 'system',
            id: null,
            name: $label,
            systemKey: $key,
        );
    }

    public static function scheduler(): self
    {
        return self::system('scheduler', 'scheduler');
    }

    public static function background(): self
    {
        return self::system('system', 'system');
    }
}
