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
 * The delivery API enforces token abilities: every surface needs a `read`
 * grant for its resource, and any non-published version scope additionally
 * needs `preview`. Tokens issued before enforcement are grandfathered to
 * `*:read` + `*:preview` by migration so already-embedded tokens keep
 * working unchanged.
 */
class TokenAbilityEnforcementTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->withLive()->create();

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => ['title' => ['type' => 'text']],
        ]);

        $this->createPublishedContent('home', ['title' => 'Home']);
    }

    #[Test]
    public function a_read_only_token_reads_published_content(): void
    {
        $token = $this->createToken(['*:read']);

        $this->getJson($this->url('api.contents.index', $token))->assertOk();
        $this->getJson($this->url('api.contents.show', $token, ['slug' => 'home']))->assertOk();
    }

    #[Test]
    public function a_read_only_token_cannot_fetch_drafts(): void
    {
        $token = $this->createToken(['*:read']);

        $this->getJson($this->url('api.contents.index', $token, ['vid' => 'draft']))->assertForbidden();
        $this->getJson($this->url('api.contents.show', $token, ['slug' => 'home', 'vid' => 'draft']))
            ->assertForbidden();
    }

    #[Test]
    public function a_preview_token_fetches_drafts(): void
    {
        $token = $this->createToken(['*:read', '*:preview']);

        $this->getJson($this->url('api.contents.index', $token, ['vid' => 'draft']))->assertOk();
        $this->getJson($this->url('api.contents.show', $token, ['slug' => 'home', 'vid' => 'draft']))
            ->assertOk();
    }

    #[Test]
    public function a_resource_scoped_token_only_reads_its_resource(): void
    {
        $token = $this->createToken(['contents:read']);

        $this->getJson($this->url('api.contents.index', $token))->assertOk();
        $this->getJson($this->url('api.blocks.index', $token))->assertForbidden();
        $this->getJson($this->url('api.spaces.show', $token))->assertForbidden();
    }

    #[Test]
    public function a_resource_scoped_preview_grant_is_honored(): void
    {
        $token = $this->createToken(['contents:read', 'contents:preview']);

        $this->getJson($this->url('api.contents.index', $token, ['vid' => 'draft']))->assertOk();
    }

    #[Test]
    public function search_and_sitemap_share_the_contents_grant(): void
    {
        $token = $this->createToken(['contents:read']);

        $this->getJson($this->url('api.contents.search', $token, ['q' => 'home']))->assertOk();
        $this->getJson($this->url('api.sitemap', $token))->assertOk();

        $blocksOnly = $this->createToken(['blocks:read']);
        $this->getJson($this->url('api.contents.search', $blocksOnly, ['q' => 'home']))->assertForbidden();
        $this->getJson($this->url('api.sitemap', $blocksOnly))->assertForbidden();
    }

    #[Test]
    public function the_grandfather_migration_widens_pre_enforcement_tokens(): void
    {
        $legacyEmpty = $this->createToken([]);
        DB::table('tokens')->where('id', $legacyEmpty->id)->update(['abilities' => json_encode([])]);

        $legacyReadOnly = $this->createToken(['*:read']);
        $alreadyScoped = $this->createToken(['contents:read', 'contents:preview']);

        $migration = require database_path('migrations/2026_07_29_000004_grandfather_delivery_token_abilities.php');
        $migration->up();

        $this->assertSame(
            ['*:read', '*:preview'],
            $legacyEmpty->fresh()->abilities->toArray(),
        );
        $this->assertSame(
            ['*:read', '*:preview'],
            $legacyReadOnly->fresh()->abilities->toArray(),
        );
        $this->assertSame(
            ['contents:read', 'contents:preview'],
            $alreadyScoped->fresh()->abilities->toArray(),
        );

        // And the widened legacy token behaves like before enforcement.
        $this->getJson($this->url('api.contents.index', $legacyEmpty->fresh(), ['vid' => 'draft']))->assertOk();
    }

    private function createToken(array $abilities): Token
    {
        return Token::factory()->withAbilities($abilities)->create([
            'space_id' => $this->space->id,
            'expires_at' => null,
        ]);
    }

    private function url(string $route, Token $token, array $query = []): string
    {
        return route($route, [
            'token' => $token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]);
    }

    private function createPublishedContent(string $slug, array $content): Content
    {
        $model = new Content;
        $model->forceFill([
            'block_id' => $this->pageBlock->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => 'en',
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
