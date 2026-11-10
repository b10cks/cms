<?php

namespace Tests\Feature\Api;

use App\Models\Management\Space;
use App\Models\Management\Token;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * An entry created in a language other than the default exists only there:
 * no other language may list or serve it as a fallback.
 */
class ContentLanguageOnlyDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Token $token;

    private Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
                'languages' => [
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => 'en'],
                    ['code' => 'fr', 'name' => 'French', 'fallback_language' => 'en'],
                ],
            ],
        ]);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'language-only-token',
            'expires_at' => null,
        ]);
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => ['title' => ['type' => 'text']],
        ]);
    }

    #[Test]
    public function fallback_listings_only_include_entries_of_the_fallback_languages(): void
    {
        $home = $this->createPublishedContent('home', 'en');
        $campaign = $this->createPublishedContent('sommeraktion', 'de');

        $german = $this->listedIds(['language' => 'de', 'include_fallback' => 1]);
        $french = $this->listedIds(['language' => 'fr', 'include_fallback' => 1]);
        $english = $this->listedIds(['language' => 'en', 'include_fallback' => 1]);

        $this->assertEqualsCanonicalizing([$home->id, $campaign->id], $german);
        $this->assertSame([$home->id], $french);
        $this->assertSame([$home->id], $english);
    }

    #[Test]
    public function an_entry_is_served_only_in_its_own_language(): void
    {
        $this->createPublishedContent('sommeraktion', 'de');

        $this->getJson($this->showUrl('sommeraktion', ['language' => 'de']))
            ->assertOk()
            ->assertJsonPath('data.content.title', 'Sommeraktion');
        $this->getJson($this->showUrl('sommeraktion', ['language' => 'en']))->assertNotFound();
        $this->getJson($this->showUrl('sommeraktion', ['language' => 'fr']))->assertNotFound();
    }

    /**
     * @return list<string>
     */
    private function listedIds(array $query): array
    {
        return collect($this->getJson(route('api.contents.index', [
            'token' => $this->token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]))->assertOk()->json('data'))->pluck('id')->all();
    }

    private function showUrl(string $slug, array $query): string
    {
        return route('api.contents.show', [
            'slug' => $slug,
            'token' => $this->token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]);
    }

    private function createPublishedContent(string $slug, string $languageIso): Content
    {
        $model = new Content;
        $model->forceFill([
            'block_id' => $this->pageBlock->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => $languageIso,
            'settings' => [],
        ]);
        $model->id = strtolower((string) Str::ulid());

        $version = ContentVersion::createWithContentContext([
            'content_id' => $model->id,
            'content' => ['title' => Str::headline($slug)],
            'published_at' => now(),
        ], $model->setRelation('block', $this->pageBlock));

        $model->current_version_id = $version->id;
        $model->published_version_id = $version->id;
        $model->published_at = $version->published_at;
        $model->save();

        return $model;
    }
}
