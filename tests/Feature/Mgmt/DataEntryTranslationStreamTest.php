<?php

namespace Tests\Feature\Mgmt;

use App\Events\Space\DataSourceContentChanged;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class DataEntryTranslationStreamTest extends TestCase
{
    use FakesAi;
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

        $this->fakeAi()->push(FakeAiDriver::text('{"hello":"Hallo","bye":"Tschüss"}'));
        $this->giveSpaceAiConfig($space);

        $dataSource = DataSource::factory()->create([
            'dimensions' => [['key' => 'en', 'label' => 'English'], ['key' => 'de', 'label' => 'German']],
            'settings' => ['dimensions_translatable' => true, 'default_dimension_locale' => 'en'],
        ]);
        DataEntry::factory()->create(['data_source_id' => $dataSource->id, 'key' => 'hello', 'value' => 'Hello', 'dimensions' => []]);
        DataEntry::factory()->create(['data_source_id' => $dataSource->id, 'key' => 'bye', 'value' => 'Bye', 'dimensions' => []]);

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

    #[Test]
    public function a_failed_batch_rolls_back_on_the_space_connection(): void
    {
        $owner = User::factory()->create();
        $space = Space::factory()->create();
        $this->assignSpaceRole($space, $owner, 'owner');
        $this->setUpSeparateSpaceDatabase($space);

        $this->fakeAi()->push(FakeAiDriver::text('{"hello":"Hallo","bye":"Tschüss"}'));
        $this->giveSpaceAiConfig($space);

        $dataSource = DataSource::factory()->create([
            'dimensions' => [['key' => 'en', 'label' => 'English'], ['key' => 'de', 'label' => 'German']],
            'settings' => ['dimensions_translatable' => true, 'default_dimension_locale' => 'en'],
        ]);
        DataEntry::factory()->create(['data_source_id' => $dataSource->id, 'key' => 'hello', 'value' => 'Hello', 'dimensions' => []]);
        DataEntry::factory()->create(['data_source_id' => $dataSource->id, 'key' => 'bye', 'value' => 'Bye', 'dimensions' => []]);
        // The second write of the batch fails; the first must not survive it.
        $saves = 0;
        DataEntry::saving(function () use (&$saves): void {
            if (++$saves === 2) {
                throw new \RuntimeException('write failed');
            }
        });

        $body = $this->actingAs($owner)->postJson(
            route('mgmt.data-sources.entries.translate-missing-dimensions.stream', [
                'space' => $space->id,
                'data_source' => $dataSource->id,
            ]),
            ['target_dimension' => 'de'],
        )->streamedContent();

        $this->assertStringContainsString('"type":"error"', $body);
        $this->assertSame([[], []], DataEntry::query()->pluck('dimensions')->all());
    }
}
