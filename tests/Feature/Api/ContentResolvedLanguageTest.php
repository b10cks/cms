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
 * `resolved_language_iso` and `is_fallback` tell a client which language an
 * entry was really served in, so a real translation, a fallback and an entry
 * that exists in one non-default language only can be told apart.
 */
class ContentResolvedLanguageTest extends TestCase
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
                    ['code' => 'nl', 'name' => 'Dutch', 'fallback_language' => 'en'],
                ],
            ],
        ]);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'resolved-language-token',
            'expires_at' => null,
        ]);
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => ['title' => ['type' => 'text', 'translatable' => true]],
        ]);
    }

    #[Test]
    public function a_real_translation_is_served_in_the_requested_language(): void
    {
        $home = $this->createContent('home', 'en');
        $this->createContent('home', 'de', $home->id);

        $this->getJson($this->showUrl('home', ['language' => 'de']))
            ->assertOk()
            ->assertJsonPath('data.language_iso', 'de')
            ->assertJsonPath('data.resolved_language_iso', 'de')
            ->assertJsonPath('data.is_fallback', false);
    }

    #[Test]
    public function an_entry_without_a_translation_is_flagged_as_a_fallback(): void
    {
        $this->createContent('appointment', 'en');

        $this->getJson($this->showUrl('appointment', ['language' => 'nl']))
            ->assertOk()
            ->assertJsonPath('data.language_iso', 'nl')
            ->assertJsonPath('data.resolved_language_iso', 'en')
            ->assertJsonPath('data.is_fallback', true);

        $this->getJson($this->indexUrl(['language' => 'nl', 'include_fallback' => 1]))
            ->assertOk()
            ->assertJsonPath('data.0.resolved_language_iso', 'en')
            ->assertJsonPath('data.0.is_fallback', true);
    }

    #[Test]
    public function an_unpublished_translation_falls_back_in_the_published_scope(): void
    {
        $home = $this->createContent('home', 'en');
        $this->createContent('startseite', 'de', $home->id, published: false);

        $this->getJson($this->showUrl('home', ['language' => 'de']))
            ->assertOk()
            ->assertJsonPath('data.content.title', 'Home en')
            ->assertJsonPath('data.resolved_language_iso', 'en')
            ->assertJsonPath('data.is_fallback', true);
    }

    #[Test]
    public function a_single_language_entry_is_not_a_fallback(): void
    {
        $this->createContent('matras-kopen', 'nl');

        $this->getJson($this->showUrl('matras-kopen', ['language' => 'nl']))
            ->assertOk()
            ->assertJsonPath('data.language_iso', 'nl')
            ->assertJsonPath('data.resolved_language_iso', 'nl')
            ->assertJsonPath('data.is_fallback', false);
    }

    private function indexUrl(array $query): string
    {
        return route('api.contents.index', [
            'token' => $this->token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]);
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

    private function createContent(string $slug, string $languageIso, ?string $i18nParentId = null, bool $published = true): Content
    {
        $model = new Content;
        $model->forceFill([
            'block_id' => $this->pageBlock->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParentId,
            'settings' => [],
        ]);
        $model->id = strtolower((string) Str::ulid());

        $version = ContentVersion::createWithContentContext([
            'content_id' => $model->id,
            'content' => ['title' => Str::headline($slug)." {$languageIso}"],
            'published_at' => $published ? now() : null,
        ], $model->setRelation('block', $this->pageBlock));

        $model->current_version_id = $version->id;
        if ($published) {
            $model->published_version_id = $version->id;
            $model->published_at = $version->published_at;
        }
        $model->save();

        return $model;
    }
}
