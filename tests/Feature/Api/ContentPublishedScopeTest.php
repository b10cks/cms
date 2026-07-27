<?php

namespace Tests\Feature\Api;

use App\Models\Management\Space;
use App\Models\Management\Token;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * The published delivery scope has to hold for entries reached *through* a
 * published entry too. `published_version_id` survives an unpublish, so a
 * relation or link pointing at a taken-down entry still had a version to
 * render — which handed out unreleased payloads, names and slugs.
 */
class ContentPublishedScopeTest extends TestCase
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
                    ['code' => 'en', 'name' => 'English', 'fallback_language' => null],
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => 'en'],
                ],
            ],
        ]);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'published-scope-token',
            'expires_at' => null,
        ]);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text'],
                'cta' => ['type' => 'link'],
                'related' => ['type' => 'references'],
            ],
        ]);
    }

    #[Test]
    public function an_unpublished_relation_is_not_expanded(): void
    {
        $secret = $this->createPublishedContent('secret-launch', ['title' => 'Secret launch']);
        $visible = $this->createPublishedContent('visible', ['title' => 'Visible']);
        $home = $this->createPublishedContent('home', [
            'title' => 'Home',
            'related' => [$secret->id, $visible->id],
        ]);

        $this->unpublish($secret);

        $response = $this->getJson($this->showUrl($home->slug, ['resolve_relations' => 1]))
            ->assertOk();

        $ids = collect($response->json('data.relations'))->pluck('id')->all();

        $this->assertSame([$visible->id], $ids);
        $response->assertDontSee('Secret launch');
        $response->assertDontSee('secret-launch');
    }

    #[Test]
    public function a_relation_that_was_never_published_is_not_expanded(): void
    {
        $draft = $this->createPublishedContent('draft-only', ['title' => 'Draft only']);
        $draft->forceFill(['published_at' => null, 'published_version_id' => null])->save();

        $home = $this->createPublishedContent('home', [
            'title' => 'Home',
            'related' => [$draft->id],
        ]);

        $this->getJson($this->showUrl($home->slug, ['resolve_relations' => 1]))
            ->assertOk()
            ->assertJsonCount(0, 'data.relations');
    }

    /**
     * Links leak less than relations but leak the two things that matter most
     * about unreleased work: what it is called and where it will live.
     */
    #[Test]
    public function an_unpublished_link_contributes_no_name_or_slug(): void
    {
        $target = $this->createPublishedContent('secret-launch', ['title' => 'Secret launch']);
        $home = $this->createPublishedContent('home', [
            'title' => 'Home',
            'cta' => ['type' => 'internal', 'content' => $target->id],
        ]);

        $this->unpublish($target);

        $response = $this->getJson($this->showUrl($home->slug))->assertOk();

        $this->assertNull(data_get($response->json(), 'data.content.cta.url'));
        $this->assertNull(data_get($response->json(), 'data.content.cta.title'));
        $response->assertDontSee('secret-launch');
    }

    #[Test]
    public function a_published_link_still_resolves(): void
    {
        $target = $this->createPublishedContent('linked-page', ['title' => 'Linked page']);
        $home = $this->createPublishedContent('home', [
            'title' => 'Home',
            'cta' => ['type' => 'internal', 'content' => $target->id],
        ]);

        $response = $this->getJson($this->showUrl($home->slug))->assertOk();

        $this->assertSame('/linked-page', data_get($response->json(), 'data.content.cta.url'));
    }

    /**
     * The link resolves through the i18n family, which is a separate query
     * from the link row itself — so an unpublished translation is its own way
     * in.
     */
    #[Test]
    public function an_unpublished_translation_is_not_used_to_resolve_a_link(): void
    {
        $target = $this->createPublishedContent('linked-page', ['title' => 'Linked page']);
        $translation = $this->createPublishedContent(
            'geheime-seite',
            ['title' => 'Geheime Seite'],
            languageIso: 'de',
            i18nParentId: $target->id,
        );
        $this->unpublish($translation);

        $home = $this->createPublishedContent('home', [
            'title' => 'Home',
            'cta' => ['type' => 'internal', 'content' => $target->id],
        ]);

        $response = $this->getJson($this->showUrl($home->slug, ['language' => 'de']))->assertOk();

        $response->assertDontSee('geheime-seite');
        $this->assertSame('/linked-page', data_get($response->json(), 'data.content.cta.url'));
    }

    /**
     * The filter rides on the existing batched eager load, so expanding many
     * relations must not add a query per relation.
     */
    #[Test]
    public function resolving_many_relations_does_not_issue_a_query_per_relation(): void
    {
        $related = [];
        for ($i = 0; $i < 9; $i++) {
            $related[] = $this->createPublishedContent("related-{$i}", ['title' => "Related {$i}"])->id;
        }

        $small = $this->createPublishedContent('home-small', [
            'title' => 'Home',
            'related' => \array_slice($related, 0, 3),
        ]);
        $large = $this->createPublishedContent('home-large', [
            'title' => 'Home',
            'related' => $related,
        ]);

        // Warm anything cached per request-independent state first, so the
        // comparison is between the two expansions and nothing else.
        $this->countQueries($small->slug, 3);

        $withThree = $this->countQueries($small->slug, 3);
        $withNine = $this->countQueries($large->slug, 9);

        $this->assertSame(
            $withThree,
            $withNine,
            'Relation expansion issues a query per relation instead of one per batch.',
        );
    }

    /**
     * Link resolution runs once per rendered resource and needs the linked
     * entry's i18n family, which is a query of its own. Pages link to the same
     * handful of targets over and over, so that lookup is remembered.
     */
    #[Test]
    public function resolving_links_across_many_relations_does_not_query_per_item(): void
    {
        $target = $this->createPublishedContent('linked-page', ['title' => 'Linked page']);

        $related = [];
        for ($i = 0; $i < 9; $i++) {
            $related[] = $this->createPublishedContent("related-{$i}", [
                'title' => "Related {$i}",
                'cta' => ['type' => 'internal', 'content' => $target->id],
            ])->id;
        }

        $small = $this->createPublishedContent('home-small', [
            'title' => 'Home',
            'cta' => ['type' => 'internal', 'content' => $target->id],
            'related' => \array_slice($related, 0, 3),
        ]);
        $large = $this->createPublishedContent('home-large', [
            'title' => 'Home',
            'cta' => ['type' => 'internal', 'content' => $target->id],
            'related' => $related,
        ]);

        $this->countQueries($small->slug, 3);

        $withThree = $this->countQueries($small->slug, 3);
        $withNine = $this->countQueries($large->slug, 9);

        $this->assertSame(
            $withThree,
            $withNine,
            'Link resolution issues a query per rendered item instead of reusing the lookup.',
        );
    }

    #[Test]
    public function an_unrecognized_version_scope_is_rejected(): void
    {
        $this->createPublishedContent('home', ['title' => 'Home']);

        $this->getJson($this->showUrl('home', ['vid' => 'anything-else']))
            ->assertStatus(422);

        $this->getJson($this->indexUrl(['vid' => (string) Str::ulid()]))
            ->assertStatus(422);

        $this->getJson($this->indexUrl(['vid' => 'nonsense']))
            ->assertStatus(422);
    }

    #[Test]
    public function the_known_version_scopes_still_work(): void
    {
        $this->createPublishedContent('home', ['title' => 'Home']);

        $this->getJson($this->indexUrl())->assertOk();
        $this->getJson($this->indexUrl(['vid' => 'draft']))->assertOk();
        $this->getJson($this->showUrl('home'))->assertOk();
        $this->getJson($this->showUrl('home', ['vid' => 'draft']))->assertOk();
    }

    private function countQueries(string $slug, int $expectedRelations): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson($this->showUrl($slug, ['resolve_relations' => 1]))->assertOk();
        $response->assertJsonCount($expectedRelations, 'data.relations');

        $count = \count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function unpublish(Content $content): void
    {
        // Mirrors UnpublishContent: published_at is cleared, the version
        // pointer is deliberately left behind.
        $content->forceFill(['published_at' => null])->save();
    }

    private function indexUrl(array $query = []): string
    {
        return route('api.contents.index', [
            'token' => $this->token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]);
    }

    private function showUrl(string $slug, array $query = []): string
    {
        return route('api.contents.show', [
            'slug' => $slug,
            'token' => $this->token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]);
    }

    private function createPublishedContent(
        string $slug,
        array $content,
        string $languageIso = 'en',
        ?string $i18nParentId = null,
    ): Content {
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
            'content' => $content,
            'published_at' => now(),
        ], $model->setRelation('block', $this->pageBlock));

        $model->current_version_id = $version->id;
        $model->published_version_id = $version->id;
        $model->published_at = $version->published_at;
        $model->save();

        return $model->fresh();
    }
}
