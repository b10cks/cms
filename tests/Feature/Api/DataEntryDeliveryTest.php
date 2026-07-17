<?php

namespace Tests\Feature\Api;

use App\Models\Management\Space;
use App\Models\Management\Token;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class DataEntryDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Token $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->withLive()->create();
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'data-entry-delivery-token',
            'expires_at' => null,
        ]);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    private function entriesUrl(DataSource $source, array $query = []): string
    {
        return '/api/v1/datasources/' . $source->slug . '/entries?' . http_build_query(
            $query + ['token' => $this->token->token]
        );
    }

    #[Test]
    public function it_delivers_plain_string_values_for_shapeless_sources(): void
    {
        $source = DataSource::factory()->create(['slug' => 'labels']);
        DataEntry::factory()->create([
            'data_source_id' => $source->id,
            'key' => 'greeting',
            'value' => 'Hello',
            'dimensions' => ['de' => 'Hallo'],
        ]);

        $this->getJson($this->entriesUrl($source))
            ->assertOk()
            ->assertJsonPath('data.0.value', 'Hello');

        $this->getJson($this->entriesUrl($source, ['dimension' => 'de']))
            ->assertOk()
            ->assertJsonPath('data.0.value', 'Hallo');
    }

    #[Test]
    public function it_delivers_parsed_values_for_shaped_sources(): void
    {
        $source = DataSource::factory()->create([
            'slug' => 'products',
            'dimensions' => [['key' => 'de', 'label' => 'German']],
            'shape' => [
                ['key' => 'title', 'type' => 'text', 'required' => true],
                ['key' => 'count', 'type' => 'number'],
            ],
        ]);

        DataEntry::factory()->create([
            'data_source_id' => $source->id,
            'key' => 'first',
            'value' => json_encode(['title' => 'Hello', 'count' => 3]),
            'dimensions' => ['de' => json_encode(['title' => 'Hallo'])],
        ]);

        $this->getJson($this->entriesUrl($source))
            ->assertOk()
            ->assertJsonPath('data.0.value.title', 'Hello')
            ->assertJsonPath('data.0.value.count', 3);

        $this->getJson($this->entriesUrl($source, ['dimension' => 'de']))
            ->assertOk()
            ->assertJsonPath('data.0.value.title', 'Hallo');
    }

    #[Test]
    public function it_falls_back_to_the_raw_string_for_legacy_values(): void
    {
        $source = DataSource::factory()->create([
            'slug' => 'mixed',
            'shape' => [['key' => 'title', 'type' => 'text']],
        ]);

        DataEntry::factory()->create([
            'data_source_id' => $source->id,
            'key' => 'legacy',
            'value' => 'plain legacy value',
        ]);

        $this->getJson($this->entriesUrl($source))
            ->assertOk()
            ->assertJsonPath('data.0.value', 'plain legacy value');
    }
}
