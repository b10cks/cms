<?php

namespace Tests\Feature\Mgmt;

use App\Events\Space\DataSourceContentChanged;
use App\Models\Management\Space;
use App\Models\Management\SpaceAiConfig;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Services\Ai\AiStreamService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class DataEntryTranslationStreamTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function it_counts_translated_entries_and_broadcasts_the_change(): void
    {
        Event::fake([DataSourceContentChanged::class]);

        $owner = User::factory()->create();
        $space = Space::factory()->create();
        $this->assignSpaceRole($space, $owner, 'owner');
        $this->setUpSpaceTesting($space);

        $space->aiConfigs()->create([
            'name' => 'Default',
            'driver' => 'openai',
            'model' => 'gpt-test',
            'is_default' => true,
        ]);

        $dataSource = DataSource::factory()->create([
            'dimensions' => [['key' => 'en', 'label' => 'English'], ['key' => 'de', 'label' => 'German']],
            'settings' => ['dimensions_translatable' => true, 'default_dimension_locale' => 'en'],
        ]);
        DataEntry::factory()->create(['data_source_id' => $dataSource->id, 'key' => 'hello', 'value' => 'Hello', 'dimensions' => []]);
        DataEntry::factory()->create(['data_source_id' => $dataSource->id, 'key' => 'bye', 'value' => 'Bye', 'dimensions' => []]);

        $this->partialMock(AiStreamService::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('{"hello":"Hallo","bye":"Tschüss"}');
        });

        $response = $this->actingAs($owner)->postJson(
            route('mgmt.data-sources.entries.translate-missing-dimensions.stream', [
                'space' => $space->id,
                'data_source' => $dataSource->id,
            ]),
            ['target_dimension' => 'de'],
        );

        $body = $response->streamedContent();

        $this->assertStringContainsString('"translated_count":2', $body);
        $this->assertSame('Hallo', DataEntry::where('key', 'hello')->first()->dimensions['de']);
        Event::assertDispatched(DataSourceContentChanged::class);
    }
}
