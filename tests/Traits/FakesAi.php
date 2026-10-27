<?php

namespace Tests\Traits;

use App\Models\Management\Space;
use App\Services\Ai\ModelRegistry;
use Tests\Support\FakeAiDriver;
use Tests\Support\FakeModelRegistry;

trait FakesAi
{
    protected function fakeAi(bool $vision = true): FakeAiDriver
    {
        $driver = new FakeAiDriver;
        $this->app->instance(ModelRegistry::class, new FakeModelRegistry($driver, $vision));

        return $driver;
    }

    protected function giveSpaceAiConfig(Space $space): void
    {
        $space->aiConfigs()->create([
            'name' => 'Default',
            'driver' => 'fake',
            'model' => 'fake-model',
            'temperature' => 0.3,
            'max_tokens' => 1024,
            'is_default' => true,
        ]);
    }

    /**
     * Decode the `data:` lines of an SSE body.
     *
     * @return list<array<string, mixed>>
     */
    protected function sseEvents(string $body): array
    {
        return array_values(array_map(
            fn (string $line) => json_decode(substr($line, 6), true),
            array_filter(explode("\n", $body), fn (string $line) => str_starts_with($line, 'data: ')),
        ));
    }
}
