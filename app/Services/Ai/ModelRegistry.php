<?php

namespace App\Services\Ai;

use App\Models\Management\Space;
use App\Models\Management\SpaceAiKey;
use App\Services\Ai\Contracts\AiDriverInterface;
use App\Services\Ai\Drivers\BedrockDriver;
use App\Services\Ai\Drivers\OpenAiDriver;
use App\Services\Ai\Drivers\OpenRouterDriver;
use App\Services\Ai\Dto\AiModelDto;
use Illuminate\Support\Facades\Cache;

class ModelRegistry
{
    protected array $drivers = [];

    protected int $cacheTtl = 43200;

    public function __construct()
    {
        $this->registerDrivers();
    }

    protected function registerDrivers(): void
    {
        $driverConfigs = config('ai.drivers', []);

        foreach ($driverConfigs as $name => $config) {
            $driver = match ($name) {
                'openai' => new OpenAiDriver($config),
                'bedrock' => new BedrockDriver($config),
                'openrouter' => new OpenRouterDriver($config),
                default => null,
            };

            if ($driver?->isConfigured()) {
                $this->drivers[$name] = $driver;
            }
        }
    }

    public function getDriver(string $name): ?AiDriverInterface
    {
        return $this->drivers[$name] ?? null;
    }

    public function getDriverForSpace(string $driverName, Space $space): ?AiDriverInterface
    {
        $driver = $this->getDriver($driverName);

        if (! $driver) {
            return null;
        }

        // Single-key mode: every space shares the platform key by design.
        if (config('ai.mode') !== 'space') {
            return $driver;
        }

        $spaceKey = SpaceAiKey::query()
            ->forSpace($space->id)
            ->forDriver($driverName)
            ->active()
            ->first();

        // Fail closed: in per-space mode a space may only stream on its own
        // provisioned, spend-capped key. Falling back to the platform key here
        // would bypass the per-space cap entirely (and bill ineligible or
        // over-quota spaces to the global key), so return no driver instead.
        if (! $spaceKey) {
            return null;
        }

        return $driver->withApiKey($spaceKey->getDecryptedKey());
    }

    public function getEnabledDrivers(): array
    {
        return array_filter(
            $this->drivers,
            fn (AiDriverInterface $driver) => $driver->isEnabled()
        );
    }

    public function getDriverNames(): array
    {
        return array_keys($this->drivers);
    }

    public function getAllModels(): array
    {
        $models = [];

        foreach ($this->getEnabledDrivers() as $driver) {
            $driverModels = $driver->getModels();
            foreach ($driverModels as $model) {
                $models[$model->getFullId()] = $model;
            }
        }

        return $models;
    }

    public function getGroupedModels(Space $space): array
    {
        $favourites = $this->getSpaceFavourites($space);
        $grouped = [];

        foreach ($this->getEnabledDrivers() as $driverName => $driver) {
            $driverModels = $driver->getModels();

            $grouped[$driverName] = \Arr::sort(\array_map(function (AiModelDto $model) use ($favourites) {
                $modelArray = $model->toArray();
                $modelArray['is_favourite'] = \in_array($model->getFullId(), $favourites);

                return $modelArray;
            }, $driverModels), 'name');
        }

        return $grouped;
    }

    public function getModelsByDriver(string $driverName): array
    {
        $driver = $this->getDriver($driverName);

        if (! $driver || ! $driver->isEnabled()) {
            return [];
        }

        return $driver->getModels();
    }

    public function findModel(string $fullId): ?AiModelDto
    {
        [$driverName, $modelId] = explode(':', $fullId, 2) + [null, null];

        if (! $driverName || ! $modelId) {
            return null;
        }

        $driver = $this->getDriver($driverName);

        if (! $driver) {
            return null;
        }

        foreach ($driver->getModels() as $model) {
            if ($model->id === $modelId) {
                return $model;
            }
        }

        return null;
    }

    public function getSpaceFavourites(Space $space): array
    {
        return $space->settings->ai['favourites'] ?? [];
    }

    public function validateFavourites(Space $space, array $favourites): array
    {
        $validModelIds = array_keys($this->getAllModels());

        return array_values(array_filter(
            $favourites,
            fn (string $id) => in_array($id, $validModelIds)
        ));
    }

    public function bustCache(?string $driverName = null): void
    {
        if ($driverName) {
            Cache::forget("ai.models.{$driverName}");
        } else {
            foreach ($this->getDriverNames() as $name) {
                Cache::forget("ai.models.{$name}");
            }
        }
    }
}
