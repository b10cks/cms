<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\PublishContent;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\TranslationUnit;
use App\Models\User;
use App\Services\Ai\Translation\TranslationMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/** People find, correct and remove translation memory pairs that runs would otherwise reuse. */
class AiTranslationMemoryManagementTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Block $page;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => [
            'default_language' => 'en',
            'i18n_mode' => 'overlay',
            'languages' => [['code' => 'nl', 'name' => 'Dutch'], ['code' => 'fr', 'name' => 'French']],
        ]]);
        $this->assignSpaceRole($this->space, $this->user, 'admin');
        $this->setUpSpaceTesting($this->space);
        Sanctum::actingAs($this->user);

        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root',
            'schema' => ['title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true]],
        ]);
        $this->base = "/mgmt/v1/spaces/{$this->space->id}/ai/translation-memory";
    }

    #[Test]
    public function units_list_by_locale_text_origin_and_entry_with_a_capped_page_size(): void
    {
        $source = $this->entry('en', '7 cm high');
        $dutch = $this->entry('nl', '7 cm hoog', $source);
        $french = $this->entry('fr', '7 cm de haut', $source);
        $this->unit($dutch, '7 cm high', '7 cm hoog');
        $this->unit($dutch, 'Soft', 'Zacht', TranslationUnit::ORIGIN_HUMAN, 'summary');
        $this->unit($french, '7 cm high', '7 cm de haut', locale: 'fr');

        $this->getJson("{$this->base}?target_locale=nl&search=hoog")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_text', '7 cm high')
            ->assertJsonPath('data.0.origin', TranslationUnit::ORIGIN_AI_ACCEPTED)
            ->assertJsonPath('data.0.content.id', $dutch->id)
            ->assertJsonPath('data.0.content.source_id', $source->id);
        $this->getJson("{$this->base}?search=7%20cm")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("{$this->base}?source_locale=EN&target_locale=nl")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("{$this->base}?origin=human")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.target_text', 'Zacht');
        $this->getJson("{$this->base}?content_id={$dutch->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("{$this->base}?content_id={$source->id}")->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("{$this->base}?per_page=1")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson("{$this->base}?per_page=500")->assertOk()->assertJsonPath('meta.per_page', 200);
        $this->getJson("{$this->base}?origin=guessed")->assertUnprocessable()->assertJsonValidationErrors('origin');
    }

    #[Test]
    public function a_correction_is_human_text_and_wins_the_next_exact_match(): void
    {
        $dutch = $this->entry('nl', '7 cm hoog', $this->entry('en', '7 cm high'));
        $unit = $this->unit($dutch, '7 cm high', '7 cm hoog');

        $this->patchJson("{$this->base}/{$unit->id}", ['target_text' => "7 cm \n dik "])->assertOk()
            ->assertJsonPath('data.target_text', '7 cm dik')
            ->assertJsonPath('data.origin', TranslationUnit::ORIGIN_HUMAN);
        $this->patchJson("{$this->base}/{$unit->id}", ['target_text' => ''])->assertUnprocessable()->assertJsonValidationErrors('target_text');
        $this->patchJson("{$this->base}/missing", ['target_text' => 'x'])->assertNotFound();

        $this->assertSame(['height' => '7 cm dik'], app(TranslationMemory::class)->exact(['height' => '7 cm high'], 'en', 'nl'));
    }

    #[Test]
    public function units_delete_one_at_a_time_or_in_bulk_and_an_unknown_id_refuses_the_bulk(): void
    {
        $dutch = $this->entry('nl', 'Zacht', $this->entry('en', 'Soft'));
        [$a, $b, $c] = [$this->unit($dutch, 'A', 'a', path: 'a'), $this->unit($dutch, 'B', 'b', path: 'b'), $this->unit($dutch, 'C', 'c', path: 'c')];

        $this->deleteJson("{$this->base}/{$a->id}")->assertNoContent();
        $this->postJson("{$this->base}/bulk", ['unit_ids' => [$b->id, 'missing'], 'action' => 'delete'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_ids');
        $this->assertSame(2, TranslationUnit::query()->count());

        $this->postJson("{$this->base}/bulk", ['unit_ids' => [$b->id, $c->id, $c->id], 'action' => 'delete'])
            ->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertSame(0, TranslationUnit::query()->count());
    }

    #[Test]
    public function reading_needs_ai_view_and_writing_ai_manage(): void
    {
        $unit = $this->unit($this->entry('nl', 'Zacht', $this->entry('en', 'Soft')), 'Soft', 'Zacht');
        $editor = User::factory()->create();
        $this->assignSpaceRole($this->space, $editor, 'editor');
        Sanctum::actingAs($editor);

        $this->getJson($this->base)->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("{$this->base}/{$unit->id}", ['target_text' => 'Week'])->assertForbidden();
        $this->deleteJson("{$this->base}/{$unit->id}")->assertForbidden();
        $this->postJson("{$this->base}/bulk", ['unit_ids' => [$unit->id], 'action' => 'delete'])->assertForbidden();
        $this->assertSame('Zacht', $unit->fresh()->target_text);
    }

    #[Test]
    public function publishing_the_translation_again_records_what_the_entry_holds(): void
    {
        $source = $this->entry('en', '7 cm high');
        $dutch = $this->entry('nl', '', $source);
        app(PublishContent::class)->execute(['content' => ['title' => '7 cm hoog']], $dutch, $this->space, $this->user);
        $unit = TranslationUnit::query()->sole();

        $this->patchJson("{$this->base}/{$unit->id}", ['target_text' => '7 cm dik'])->assertOk();
        app(PublishContent::class)->execute(['content' => ['title' => '7 cm hoog']], $dutch->fresh(), $this->space, $this->user);

        $this->assertSame('7 cm hoog', TranslationUnit::query()->sole()->target_text);
    }

    private function unit(Content $translation, string $source, string $target, string $origin = TranslationUnit::ORIGIN_AI_ACCEPTED, string $path = 'title', string $locale = 'nl'): TranslationUnit
    {
        return TranslationUnit::query()->create([
            'source_hash' => TranslationMemory::hash($source),
            'source_locale' => 'en',
            'target_locale' => $locale,
            'source_text' => $source,
            'target_text' => $target,
            'origin' => $origin,
            'content_id' => $translation->id,
            'field_path' => $path,
        ]);
    }

    private function entry(string $language, string $title, ?Content $source = null): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => $title ?: 'Untitled',
            'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => $language,
            'i18n_parent_id' => $source?->id,
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => ['title' => $title],
            'created_by_id' => $this->user->id,
            'published_at' => $source ? null : now(),
        ]);

        $content->current_version_id = $version->id;
        $content->published_version_id = $source ? null : $version->id;
        $content->published_at = $source ? null : now();
        $content->save();

        return $content->fresh();
    }
}
