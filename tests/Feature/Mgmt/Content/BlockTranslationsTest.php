<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\Translation\TranslationMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class BlockTranslationsTest extends TestCase
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

        $this->page = $this->block('page', 'root', ['body' => ['type' => 'blocks', 'name' => 'Body']]);
        $this->block('teaser', 'nestable', [
            'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true, 'validation' => ['max_length' => 30]],
            'tone' => ['type' => 'text', 'name' => 'Tone'],
            'cards' => ['type' => 'blocks', 'name' => 'Cards'],
        ]);
        $this->block('card', 'nestable', ['label' => ['type' => 'text', 'name' => 'Label', 'translatable' => true]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function copied_blocks_bring_what_each_translation_stores_for_them_and_its_lineage(): void
    {
        $source = $this->entry('en', ['body' => [$this->teaser('t1', 'Our team', [['id' => 'c1', 'block' => 'card', 'label' => 'Join']]), $this->teaser('t2', 'Other')]]);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->entry('de', ['body' => [
            ['id' => 't1', 'block' => 'teaser', 'title' => 'Unser Team', 'cards' => [['id' => 'c1', 'block' => 'card', 'label' => 'Mitmachen']]],
            ['id' => 't2', 'block' => 'teaser', 'title' => 'Anderes'],
        ]], $source);
        $this->entry('fr', ['body' => []], $source);
        $this->postJson($this->url("contents/{$source->id}/translation-review"), ['locale' => 'de', 'ai' => ['body.t1.cards.c1.label']])->assertOk();

        $response = $this->getJson($this->url("contents/{$source->id}/block-translations?ids[]=t1&ids[]=unsaved"))->assertOk();

        $this->assertSame(['t1'], array_keys($response->json('data')));
        $this->assertSame(['de'], array_keys($response->json('data.t1')));
        $this->assertSame('Unser Team', $response->json('data.t1.de.item.title'));
        $this->assertSame('Mitmachen', $response->json('data.t1.de.item.cards.0.label'));
        $this->assertEqualsCanonicalizing([
            ['unit' => 'title', 'source_hash' => TranslationMemory::hash('Our team'), 'source_text' => 'Our team', 'origin' => 'history'],
            ['unit' => 'cards.c1.label', 'source_hash' => TranslationMemory::hash('Join'), 'source_text' => 'Join', 'origin' => 'ai'],
        ], $response->json('data.t1.de.lineage'));

        $translation = Content::query()->where('i18n_parent_id', $source->id)->where('language_iso', 'de')->firstOrFail();
        $this->assertSame([], $this->getJson($this->url("contents/{$translation->id}/block-translations?ids[]=t1"))->assertOk()->json('data'));
    }

    #[Test]
    public function saving_writes_pasted_translations_to_the_translations_the_entry_has(): void
    {
        $target = $this->entry('en', ['body' => [$this->teaser('own', 'Own')]]);
        $this->entry('de', ['body' => [['id' => 'own', 'block' => 'teaser', 'title' => 'Eigenes']]], $target);

        $this->patchJson($this->url("contents/{$target->id}"), [
            'content' => ['body' => [$this->teaser('own', 'Own', [['id' => 'n2', 'block' => 'card', 'label' => 'Join us']]), $this->teaser('n1', 'Our new team')]],
            'block_translations' => [
                'n1' => [
                    'de' => ['item' => ['id' => 'n1', 'block' => 'teaser', 'title' => 'Unser Team'], 'lineage' => [
                        ['unit' => 'title', 'source_hash' => TranslationMemory::hash('Our team'), 'source_text' => 'Our team', 'origin' => 'history'],
                    ]],
                    'fr' => ['item' => ['id' => 'n1', 'block' => 'teaser', 'title' => 'Notre équipe']],
                ],
                'n2' => ['de' => ['item' => ['id' => 'n2', 'block' => 'card', 'label' => 'Mitmachen'], 'lineage' => [
                    ['unit' => 'label', 'source_hash' => TranslationMemory::hash('Join us'), 'source_text' => 'Join us', 'origin' => 'ai'],
                ]]],
                'removed' => ['de' => ['item' => ['id' => 'removed', 'block' => 'teaser', 'title' => 'Weg']]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('block_translations', ['applied' => ['de'], 'skipped' => [['language' => 'fr', 'reason' => 'missing']]]);

        $translation = Content::query()->where('i18n_parent_id', $target->id)->where('language_iso', 'de')->firstOrFail();
        $this->assertSame([
            ['id' => 'own', 'block' => 'teaser', 'title' => 'Eigenes', 'cards' => [['id' => 'n2', 'block' => 'card', 'label' => 'Mitmachen']]],
            ['id' => 'n1', 'block' => 'teaser', 'title' => 'Unser Team'],
        ], $translation->getCurrentContent()['body']);

        $this->getJson($this->url("contents/{$target->id}/translation-status?locale=de"))
            ->assertOk()
            ->assertJson(['data' => ['units' => [
                'body.own.title' => ['status' => 'current'],
                'body.own.cards.n2.label' => ['status' => 'current', 'review' => true],
                'body.n1.title' => ['status' => 'stale', 'previous_source' => 'Our team'],
            ]]]);
    }

    #[Test]
    public function a_translation_the_pasted_values_do_not_validate_in_is_skipped(): void
    {
        $target = $this->entry('en', ['body' => []]);
        $german = $this->entry('de', ['body' => []], $target);
        $this->entry('fr', ['body' => []], $target);

        $this->patchJson($this->url("contents/{$target->id}"), [
            'content' => ['body' => [$this->teaser('n1', 'Our team')]],
            'block_translations' => ['n1' => [
                'de' => ['item' => ['id' => 'n1', 'block' => 'teaser', 'title' => 'Ein Titel, der für dieses Feld viel zu lang geraten ist']],
                'fr' => ['item' => ['id' => 'n1', 'block' => 'teaser', 'title' => 'Notre équipe']],
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('block_translations', ['applied' => ['fr'], 'skipped' => [['language' => 'de', 'reason' => 'invalid']]]);

        $this->assertSame(['body' => []], $german->fresh()->getCurrentContent());
        $this->assertSame('n1', $target->fresh()->getCurrentContent()['body'][0]['id']);
    }

    #[Test]
    public function an_independent_entry_takes_no_block_translations(): void
    {
        $target = $this->entry('en', ['body' => []], settings: ['i18n_mode_override' => 'independent']);
        $german = $this->entry('de', ['body' => []], $target);

        $this->patchJson($this->url("contents/{$target->id}"), [
            'content' => ['body' => [$this->teaser('n1', 'Our team')]],
            'block_translations' => ['n1' => ['de' => ['item' => ['id' => 'n1', 'block' => 'teaser', 'title' => 'Unser Team']]]],
        ])
            ->assertOk()
            ->assertJsonPath('block_translations', ['applied' => [], 'skipped' => []]);

        $this->assertSame(['body' => []], $german->fresh()->getCurrentContent());
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    private function teaser(string $id, string $title, array $cards = []): array
    {
        return ['id' => $id, 'block' => 'teaser', 'title' => $title, 'tone' => 'calm', 'cards' => $cards];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function block(string $slug, string $type, array $schema): Block
    {
        return Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => $type,
            'schema' => $schema,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $settings
     */
    private function entry(string $language, array $values, ?Content $canonical = null, array $settings = []): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => 'Entry',
            'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => $language,
            'i18n_parent_id' => $canonical?->id,
            'settings' => $settings,
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
