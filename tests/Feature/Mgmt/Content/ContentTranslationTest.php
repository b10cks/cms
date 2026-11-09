<?php

namespace Tests\Feature\Mgmt\Content;

use App\Actions\Content\UpdateContent;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\TranslationUnit;
use App\Models\User;
use App\Services\Ai\Translation\TranslationMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentTranslationTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Block $page;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->user = User::factory()->create();
        $this->space = Space::factory()->create([
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
                'languages' => [
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => null],
                    ['code' => 'fr', 'name' => 'French', 'fallback_language' => null],
                ],
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->actingAs($this->user);

        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
                'summary' => ['type' => 'text', 'name' => 'Summary', 'translatable' => true],
                'lead' => ['type' => 'text', 'name' => 'Lead', 'translatable' => true],
                'seo' => ['type' => 'meta', 'name' => 'SEO', 'translatable' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function status_names_each_unit_and_gives_a_stale_unit_the_source_it_was_translated_from(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things', 'lead' => 'Hello', 'seo' => ['title' => 'Team']]);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->entry('de', ['title' => 'Über uns', 'summary' => '', 'lead' => 'Hallo', 'seo' => ['title' => 'Team']], $source);
        Carbon::setTestNow('2026-10-01 11:00:00');
        app(UpdateContent::class)->execute(['content' => [...$source->getCurrentContent(), 'title' => 'About our team'], 'force' => true], $source->fresh(), $this->space, $this->user);

        $this->getJson($this->url("contents/{$source->id}/translation-status?locale=de"))
            ->assertOk()
            ->assertJsonPath('data.locale', 'de')
            ->assertJsonPath('data.source_locale', 'en')
            ->assertJsonPath('data.units', [
                'title' => ['status' => 'stale', 'previous_source' => 'About us'],
                'summary' => ['status' => 'empty'],
                'seo.title' => ['status' => 'same_as_source'],
                'lead' => ['status' => 'current'],
            ]);
    }

    #[Test]
    public function status_reads_every_unit_as_missing_before_the_translation_exists(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);

        $this->getJson($this->url("contents/{$source->id}/translation-status?locale=de"))
            ->assertOk()
            ->assertJsonPath('data.translation_id', null)
            ->assertJsonPath('data.units', ['title' => ['status' => 'missing'], 'summary' => ['status' => 'missing']]);

        $this->getJson($this->url("contents/{$source->id}/translation-status?locale=en"))->assertUnprocessable();
    }

    #[Test]
    public function assist_brings_memory_and_other_locales(): void
    {
        $other = strtolower((string) Str::ulid());
        TranslationUnit::query()->forceCreate([
            'source_hash' => TranslationMemory::hash('Your cart'), 'source_locale' => 'en', 'target_locale' => 'de',
            'source_text' => 'Your cart', 'target_text' => 'Ihr Warenkorb', 'origin' => 'human',
            'content_id' => $other, 'field_path' => 'summary',
        ]);
        $source = $this->entry('en', ['title' => 'Open the cart', 'summary' => 'Your cart']);
        $this->entry('fr', ['summary' => 'Votre panier'], $source);

        $response = $this->postJson($this->url("contents/{$source->id}/translation-assist"), ['locale' => 'de', 'unit' => 'summary', 'text' => 'Ihr Wagen'])
            ->assertOk()
            ->assertJsonPath('data.source', 'Your cart')
            ->assertJsonPath('data.target', 'Ihr Wagen')
            ->assertJsonPath('data.locales', [['locale' => 'fr', 'text' => 'Votre panier']]);

        $this->assertSame(
            ['score' => 1, 'source' => 'Your cart', 'target' => 'Ihr Warenkorb', 'origin' => 'human', 'content_id' => $other, 'field_path' => 'summary', 'content_name' => null],
            $response->json('data.memory.0'),
        );

        $this->postJson($this->url("contents/{$source->id}/translation-assist"), ['locale' => 'de', 'unit' => 'nope'])->assertNotFound();
    }

    #[Test]
    public function both_endpoints_require_space_membership(): void
    {
        $source = $this->entry('en', ['title' => 'About us']);
        $this->actingAs(User::factory()->create());

        $this->getJson($this->url("contents/{$source->id}/translation-status?locale=de"))->assertForbidden();
        $this->postJson($this->url("contents/{$source->id}/translation-assist"), ['locale' => 'de', 'unit' => 'title'])->assertForbidden();
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function entry(string $language, array $values, ?Content $canonical = null): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => ($values['title'] ?? '') ?: 'Untitled',
            'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => $language,
            'i18n_parent_id' => $canonical?->id,
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => $values,
            'created_by_id' => $this->user->id,
        ]);

        $content->current_version_id = $version->id;
        $content->save();

        return $content->fresh();
    }
}
