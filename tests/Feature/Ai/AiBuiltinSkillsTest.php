<?php

namespace Tests\Feature\Ai;

use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

/**
 * The features that used their own stream endpoints now run as built-in
 * skills and return the same structured result. Asset classification is
 * covered by AssetClassificationTest.
 */
class AiBuiltinSkillsTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private FakeAiDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        Sanctum::actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $data
     */
    #[Test]
    #[DataProvider('features')]
    public function each_migrated_feature_returns_the_same_structured_result(string $surface, string $skill, array $context, array $data): void
    {
        $this->driver->push(FakeAiDriver::text("```json\n".json_encode($data)."\n```"));

        $events = $this->sseEvents($this->postJson("/mgmt/v1/spaces/{$this->space->id}/ai/runs", [
            'surface' => $surface,
            'skill' => $skill,
            'prompt' => 'Do the thing.',
            'context' => $context,
        ])->assertOk()->streamedContent());

        $completed = collect($events)->firstWhere('type', 'completed');
        $this->assertSame($data, $completed['result']['data']);
        $this->assertFalse($completed['partial']);
    }

    public static function features(): array
    {
        return [
            'content' => ['canvas', 'content-interaction', ['content' => ['title' => 'Old']], ['title' => 'New']],
            'tree' => ['content-tree', 'content-tree', ['tree' => [], 'mentions' => []], ['operations' => [['type' => 'create', 'name' => 'About', 'slug' => 'about', 'parent_id' => null, 'block_id' => '01H2X3Y4Z5A6B7C8D9E0F1G2H3', 'temp_id' => 'temp_1']]]],
            'meta tags' => ['meta-tags', 'meta-tags', ['language' => 'de', 'page' => ['title' => 'Hallo']], ['title' => 'T', 'description' => 'D', 'ogTitle' => 'OT', 'ogDescription' => 'OD']],
            'translation' => ['translate', 'translate-fields', ['source' => 'en', 'target' => 'de', 'fields' => ['title' => 'Hello']], ['title' => 'Hallo']],
        ];
    }

    #[Test]
    public function an_answer_that_misses_the_output_schema_gets_a_correction(): void
    {
        $this->driver->push(
            FakeAiDriver::text('{"title": "Only a title"}'),
            FakeAiDriver::text('{"title": "T", "description": "D", "ogTitle": "OT", "ogDescription": "OD"}'),
        );

        $events = $this->sseEvents($this->postJson("/mgmt/v1/spaces/{$this->space->id}/ai/runs", [
            'surface' => 'meta-tags',
            'skill' => 'meta-tags',
            'context' => ['language' => 'en', 'page' => []],
        ])->assertOk()->streamedContent());

        $this->assertSame(2, $this->driver->calls);
        $this->assertStringContainsString('$.description is required', collect($this->driver->transcripts[1])->last()['content']);
        $this->assertSame('D', collect($events)->firstWhere('type', 'completed')['result']['data']['description']);
    }

    #[Test]
    public function data_entry_translation_fills_missing_dimensions_through_the_skill(): void
    {
        $source = DataSource::factory()->create([
            'dimensions' => [['key' => 'en', 'name' => 'English'], ['key' => 'de', 'name' => 'German']],
            'settings' => ['dimensions_translatable' => true, 'default_dimension_locale' => 'en'],
        ]);
        DataEntry::factory()->create(['data_source_id' => $source->id, 'key' => 'yes', 'value' => 'Yes', 'dimensions' => ['en' => 'Yes']]);
        DataEntry::factory()->create(['data_source_id' => $source->id, 'key' => 'no', 'value' => 'No', 'dimensions' => ['en' => 'No']]);
        $this->driver->push(FakeAiDriver::text('{"yes": "Ja", "no": "Nein"}'));

        $events = $this->sseEvents($this->postJson(route('mgmt.data-sources.entries.translate-missing-dimensions.stream', [
            'space' => $this->space->id,
            'data_source' => $source->id,
        ]), ['target_dimension' => 'de'])->assertOk()->streamedContent());

        $this->assertSame(2, collect($events)->firstWhere('type', 'done')['data']['total_candidates']);
        $this->assertSame('Ja', DataEntry::query()->where('key', 'yes')->first()->dimensions['de']);
        $this->assertSame('Nein', DataEntry::query()->where('key', 'no')->first()->dimensions['de']);
        $this->assertStringContainsString('"entries"', $this->driver->lastUserText());
    }
}
