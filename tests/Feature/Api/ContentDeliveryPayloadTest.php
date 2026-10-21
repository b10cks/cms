<?php

namespace Tests\Feature\Api;

use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Management\Token;
use App\Models\Space\Asset;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * Locks the delivery index and show output and their query counts on a
 * fixture with links, assets, relations and two languages. The snapshot in
 * tests/fixtures/delivery-payload-snapshot.json was captured before the
 * payload optimizations, so any change to it is a change in output.
 */
class ContentDeliveryPayloadTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private const string SNAPSHOT = __DIR__.'/../../fixtures/delivery-payload-snapshot.json';

    private Space $space;

    private Token $token;

    private Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 12:00:00');

        $this->space = Space::factory()->withLive()->create([
            'id' => '01k60000000000000000000001',
            'settings' => [
                'default_language' => 'en',
                'languages' => [
                    ['code' => 'en', 'name' => 'English', 'fallback_language' => null],
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => 'en'],
                ],
            ],
        ]);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'delivery-payload-token',
            'expires_at' => null,
        ]);
        $storage = Storage::factory()->create([
            'id' => '01k60000000000000000000002',
            'space_id' => $this->space->id,
            'is_default' => true,
            'driver' => 'local',
            'state' => 'live',
        ]);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => 'page',
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'translatable' => true],
                'image' => ['type' => 'asset'],
                'cta' => ['type' => 'link'],
                'body' => ['type' => 'richtext', 'translatable' => true],
                'related' => ['type' => 'references'],
            ],
        ]);

        $asset = new Asset;
        $asset->forceFill([
            'id' => '01k60000000000000000000003',
            'filename' => 'hero',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'storage_id' => $storage->id,
            'size' => 1234,
            'path' => "{$this->space->id}/01k60000000000000000000003/hero.jpg",
            'metadata' => ['type' => 'image', 'width' => 800, 'height' => 600],
            'data' => ['fields' => ['_default' => ['alt' => 'Hero'], 'de' => ['alt' => 'Held']]],
        ])->save();

        $target = $this->createEntry('01k60000000000000000000004', 'linked-page', 1, ['title' => 'Linked page']);
        $this->createEntry('01k60000000000000000000005', 'verlinkte-seite', 1, ['title' => 'Verlinkte Seite'], 'de', $target->id);
        $related = $this->createEntry('01k60000000000000000000006', 'related-page', 2, ['title' => 'Related']);

        $home = $this->createEntry('01k60000000000000000000007', 'home', 3, [
            'title' => 'Home',
            'image' => ['type' => 'asset', 'id' => $asset->id],
            'cta' => ['type' => 'internal', 'content' => $target->id],
            'body' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'internalLink',
                        'attrs' => ['content' => $target->id],
                        'content' => [['type' => 'text', 'text' => 'Read more']],
                    ]],
                ]],
            ],
            'related' => [$related->id],
        ]);
        $this->createEntry('01k60000000000000000000008', 'startseite', 3, ['title' => 'Startseite'], 'de', $home->id);

        $plain = $this->createEntry('01k60000000000000000000009', 'plain', 4, ['title' => 'Plain']);
        $draft = ContentVersion::createWithContentContext([
            'content_id' => $plain->id,
            'content' => ['title' => 'Plain draft'],
        ], $plain);
        $plain->forceFill(['current_version_id' => $draft->id])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function delivery_responses_match_the_pre_optimization_snapshot(): void
    {
        $snapshot = json_decode((string) file_get_contents(self::SNAPSHOT), true);

        foreach ($this->requests() as $name => [$url]) {
            $this->assertSame($snapshot[$name], $this->getJson($url)->assertOk()->json(), $name);
        }
    }

    #[Test]
    public function delivery_responses_stay_within_their_query_budget(): void
    {
        foreach ($this->requests() as $name => [$url, $budget]) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            $this->assertCount($budget, $queries, $name);

            foreach ($queries as $sql) {
                $this->assertStringNotContainsString('select * from "contents"', $sql, $name);
                $this->assertStringNotContainsString('"content_versions"."link_ids"', $sql, $name);
            }
        }
    }

    /**
     * Each request with its expected query count. The count includes the
     * space_connections load of the token lookup, which tests otherwise skip.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    private function requests(): array
    {
        return [
            'index_en' => [$this->url('api.contents.index', ['sort' => 'position', 'language' => 'en']), 12],
            'index_de_fallback' => [$this->url('api.contents.index', ['sort' => 'position', 'language' => 'de', 'include_fallback' => 1]), 13],
            'index_de_draft_fallback' => [$this->url('api.contents.index', ['sort' => 'position', 'language' => 'de', 'include_fallback' => 1, 'vid' => 'draft']), 14],
            'show_home' => [$this->url('api.contents.show', ['slug' => 'home', 'resolve_relations' => 1]), 16],
            'show_home_de' => [$this->url('api.contents.show', ['slug' => 'startseite', 'language' => 'de', 'resolve_relations' => 1]), 18],
            'show_plain' => [$this->url('api.contents.show', ['slug' => 'plain']), 9],
            'show_plain_draft' => [$this->url('api.contents.show', ['slug' => 'plain', 'vid' => 'draft']), 9],
        ];
    }

    private function url(string $route, array $query): string
    {
        return route($route, [
            'token' => $this->token->token,
            'rv' => $this->space->rv,
            ...$query,
        ]);
    }

    private function createEntry(
        string $id,
        string $slug,
        int $position,
        array $content,
        string $languageIso = 'en',
        ?string $i18nParentId = null,
    ): Content {
        $model = new Content;
        $model->forceFill([
            'external_id' => $id,
            'block_id' => $this->pageBlock->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'position' => $position,
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParentId,
            'settings' => [],
        ]);
        $model->id = $id;

        $version = ContentVersion::createWithContentContext([
            'content_id' => $model->id,
            'content' => $content,
            'published_at' => now(),
        ], $model->setRelation('block', $this->pageBlock));

        $model->current_version_id = $version->id;
        $model->published_version_id = $version->id;
        $model->published_at = $version->published_at;
        $model->save();

        return $model;
    }
}
