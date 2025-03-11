<?php

namespace App\Services;

use Illuminate\Support\Collection;

class TokenAbility
{
    private const array ALLOWED_ACTIONS = ['read', 'create', 'update', 'delete'];

    private Collection $abilities;

    public function __construct(array $abilities)
    {
        $this->abilities = collect($abilities)->map(function ($ability) {
            if (!str_contains($ability, ':')) {
                return "*:$ability";
            }
            return $ability;
        });
    }

    public static function fromArray(array $abilities): self
    {
        return new self($abilities);
    }

    public function toArray(): array
    {
        return $this->abilities->all();
    }

    public function hasAbility(string $resource, string $action): bool
    {
        // Check for exact match or wildcard
        return $this->abilities->contains("$resource:$action") ||
            $this->abilities->contains("*:$action");
    }

    public static function getAllowedActions(): array
    {
        return self::ALLOWED_ACTIONS;
    }

    public function getResourceAbilities(string $resource): array
    {
        $resourceAbilities = $this->abilities
            ->filter(fn ($ability) => str_starts_with($ability, "$resource:"))
            ->map(fn ($ability) => explode(':', $ability)[1])
            ->values()
            ->all();

        // Include wildcard abilities
        $wildcardAbilities = $this->abilities
            ->filter(fn ($ability) => str_starts_with($ability, '*:'))
            ->map(fn ($ability) => explode(':', $ability)[1])
            ->values()
            ->all();

        return array_unique(array_merge($resourceAbilities, $wildcardAbilities));
    }

    public function validate(): bool
    {
        return $this->abilities->every(function ($ability) {
            [$resource, $action] = array_pad(explode(':', $ability), 2, null);
            return $resource && $action && in_array($action, self::ALLOWED_ACTIONS);
        });
    }
}
