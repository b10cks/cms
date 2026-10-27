<?php

namespace Tests\Support;

use App\Models\Management\Space;
use App\Services\Ai\Contracts\AiDriverInterface;
use App\Services\Ai\Dto\AiModelDto;
use App\Services\Ai\ModelRegistry;

/** Hands every space the fake driver; models are vision-capable unless told otherwise. */
class FakeModelRegistry extends ModelRegistry
{
    public function __construct(
        public AiDriverInterface $driver,
        public bool $vision = true,
    ) {}

    public function getDriver(string $name): ?AiDriverInterface
    {
        return $this->driver;
    }

    public function getDriverForSpace(string $driverName, Space $space): ?AiDriverInterface
    {
        return $this->driver;
    }

    public function findModel(string $fullId): ?AiModelDto
    {
        return null;
    }

    public function findModelForConfig(?string $driver, ?string $model): ?AiModelDto
    {
        return new AiModelDto(id: (string) $model, name: (string) $model, driver: (string) $driver, capabilities: ['text', 'vision'], supportsVision: $this->vision);
    }
}
