<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentSchemaValidationTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
                'languages' => [
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => null],
                ],
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'toggle' => [
                    'type' => 'boolean',
                    'name' => 'Toggle',
                ],
                'headline' => [
                    'type' => 'text',
                    'name' => 'Headline',
                    'required' => true,
                    'conditions' => [
                        'mode' => 'all',
                        'rules' => [[
                            'field' => 'toggle',
                            'operator' => 'equals',
                            'value' => true,
                        ]],
                    ],
                ],
                'summary' => [
                    'type' => 'text',
                    'name' => 'Summary',
                    'required' => true,
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['toggle', 'headline', 'summary'],
            ]],
        ]);
    }

    #[Test]
    public function hidden_fields_are_pruned_before_the_version_is_saved(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'name' => 'Home',
            'slug' => 'home',
            'block_id' => $this->pageBlock->id,
            'language_iso' => 'en',
            'content' => [
                'toggle' => false,
                'headline' => 'Should be removed',
                'summary' => 'Visible summary',
            ],
        ]);

        $response->assertCreated();

        $content = Content::query()->firstOrFail()->load('current_version');

        $this->assertArrayNotHasKey('headline', $content->current_version->content);
        $this->assertSame('Visible summary', $content->current_version->content['summary']);
    }

    #[Test]
    public function visible_conditional_fields_are_validated(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'name' => 'Home',
            'slug' => 'home',
            'block_id' => $this->pageBlock->id,
            'language_iso' => 'en',
            'content' => [
                'toggle' => true,
                'summary' => 'Visible summary',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.headline']);
    }

    #[Test]
    public function switching_current_is_allowed_while_publishing_invalid_historical_versions_is_rejected(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createContent([
            'toggle' => false,
            'summary' => 'Visible summary',
        ]);

        $invalidVersion = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'parent_id' => $content->current_version_id,
            'content' => [
                'toggle' => true,
                'summary' => 'Visible summary',
            ],
            'created_by_id' => $this->owner->id,
        ]);

        // Switching the current (draft) version is a force-save and skips validation.
        $this->postJson(route('mgmt.contents.versions.current', [
            'space' => $this->space->id,
            'content' => $content->id,
            'version' => $invalidVersion->id,
        ]))
            ->assertStatus(204);

        $this->postJson(route('mgmt.contents.versions.publish', [
            'space' => $this->space->id,
            'content' => $content->id,
            'version' => $invalidVersion->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.headline']);
    }

    #[Test]
    public function translation_conditionals_use_effective_overlay_values_and_clear_hidden_local_fields(): void
    {
        $this->actingAs($this->owner);

        $translationBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Promo Page',
            'slug' => 'promoPage',
            'type' => 'root',
            'schema' => [
                'status' => [
                    'type' => 'option',
                    'name' => 'Status',
                    'translatable' => true,
                    'options' => [
                        ['name' => 'Show', 'value' => 'show'],
                        ['name' => 'Hide', 'value' => 'hide'],
                    ],
                ],
                'promo_title' => [
                    'type' => 'text',
                    'name' => 'Promo Title',
                    'required' => true,
                    'translatable' => true,
                    'conditions' => [
                        'mode' => 'all',
                        'rules' => [[
                            'field' => 'status',
                            'operator' => 'equals',
                            'value' => 'show',
                        ]],
                    ],
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['status', 'promo_title'],
            ]],
        ]);

        $canonical = $this->createContent([
            'status' => 'show',
            'promo_title' => 'Default promo',
        ], $translationBlock, 'en');

        $createResponse = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'name' => 'Promo DE',
            'slug' => 'promo-de',
            'block_id' => $translationBlock->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
            'content' => [],
        ]);

        $createResponse->assertCreated();

        $translation = Content::query()
            ->where('i18n_parent_id', $canonical->id)
            ->where('language_iso', 'de')
            ->firstOrFail();

        $updateResponse = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $translation->id,
        ]), [
            'content' => [
                'status' => 'hide',
                'promo_title' => 'Should be removed',
            ],
        ]);

        $updateResponse->assertOk();

        $translation->refresh()->load('current_version');

        $this->assertSame('hide', $translation->current_version->content['status']);
        $this->assertArrayNotHasKey('promo_title', $translation->current_version->content);
    }

    #[Test]
    public function publishing_a_localized_overlay_ignores_missing_non_translatable_fields(): void
    {
        $this->actingAs($this->owner);

        $translationBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Localized Page',
            'slug' => 'localizedPage',
            'type' => 'root',
            'schema' => [
                'media' => [
                    'type' => 'asset',
                    'name' => 'Media',
                    'required' => true,
                ],
                'headline' => [
                    'type' => 'text',
                    'name' => 'Headline',
                    'required' => true,
                    'translatable' => true,
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['media', 'headline'],
            ]],
        ]);

        $canonical = $this->createContent([
            'headline' => 'Default headline',
        ], $translationBlock, 'en');

        $translation = $this->createContent([
            'media' => null,
            'headline' => 'Lokalisierte Headline',
        ], $translationBlock, 'de', $canonical);

        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $translation->id,
        ]), [
            'content' => [
                'headline' => 'Lokalisierte Headline',
            ],
        ])->assertOk();

        $translation->refresh()->load('published_version');

        $this->assertNotNull($translation->published_at);
        $this->assertSame(
            ['headline' => 'Lokalisierte Headline'],
            $translation->published_version?->content
        );
    }

    #[Test]
    public function storing_a_localized_overlay_ignores_explicit_non_translatable_values(): void
    {
        $this->actingAs($this->owner);

        $heroBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Localized Hero',
            'slug' => 'localizedHero',
            'type' => 'nestable',
            'schema' => [
                'media' => [
                    'type' => 'asset',
                    'name' => 'Media',
                    'required' => true,
                ],
                'headline' => [
                    'type' => 'text',
                    'name' => 'Headline',
                    'required' => true,
                    'translatable' => true,
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['media', 'headline'],
            ]],
        ]);

        $compoundBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Compound Page',
            'slug' => 'compoundPage',
            'type' => 'root',
            'schema' => [
                'body' => [
                    'type' => 'blocks',
                    'name' => 'Body',
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['body'],
            ]],
        ]);

        $canonical = $this->createContent([
            'body' => [[
                'id' => (string) Str::uuid(),
                'block' => 'localizedHero',
                'media' => [
                    'type' => 'asset',
                    'id' => '01assetcanonical0000000000000000',
                ],
                'headline' => 'Default headline',
            ]],
        ], $compoundBlock, 'en');

        $response = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'name' => 'Hero DE',
            'slug' => 'hero-de',
            'block_id' => $compoundBlock->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
            'content' => [
                'body' => [[
                    'id' => (string) Str::uuid(),
                    'block' => 'localizedHero',
                    'media' => null,
                    'headline' => 'Lokalisierte Headline',
                ]],
            ],
        ]);

        $response->assertCreated();

        $translation = Content::query()
            ->where('i18n_parent_id', $canonical->id)
            ->where('language_iso', 'de')
            ->firstOrFail()
            ->load('current_version');

        $this->assertSame('Lokalisierte Headline', data_get($translation->current_version?->content, 'body.0.headline'));
        $this->assertNull(data_get($translation->current_version?->content, 'body.0.media'));
        $this->assertArrayNotHasKey('media', data_get($translation->current_version?->content, 'body.0', []));
    }

    #[Test]
    public function clearing_a_translatable_field_in_an_overlay_falls_back_to_the_parent_value_not_the_own_stale_one(): void
    {
        $this->actingAs($this->owner);

        $block = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Coded Page',
            'slug' => 'codedPage',
            'type' => 'root',
            'schema' => [
                'code' => [
                    'type' => 'text',
                    'name' => 'Code',
                    'translatable' => true,
                    'validation' => ['pattern' => '/^[a-z-]+$/'],
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['code'],
            ]],
        ]);

        $canonical = $this->createContent(['code' => 'valid-code'], $block, 'en');
        // The translation's persisted value violates the (since tightened) pattern.
        $translation = $this->createContent(['code' => 'STALE!!'], $block, 'de', $canonical);

        // Clearing the field means "inherit from the parent again" — the parent's
        // valid value must be validated, not the translation's old stale one.
        $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $translation->id,
        ]), [
            'content' => ['code' => ''],
        ])->assertOk();
    }

    #[Test]
    public function publishing_an_overlay_with_a_cleared_required_translatable_field_fails_when_the_parent_has_no_value(): void
    {
        $this->actingAs($this->owner);

        $block = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Headline Page',
            'slug' => 'headlinePage',
            'type' => 'root',
            'schema' => [
                'headline' => [
                    'type' => 'text',
                    'name' => 'Headline',
                    'required' => true,
                    'translatable' => true,
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['headline'],
            ]],
        ]);

        $canonical = $this->createContent([], $block, 'en');
        $translation = $this->createContent(['headline' => 'Alte Überschrift'], $block, 'de', $canonical);

        // The old own value must not satisfy the requirement — after this publish
        // the overlay would resolve to the (empty) parent chain.
        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $translation->id,
        ]), [
            'content' => ['headline' => ''],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.headline']);
    }

    #[Test]
    public function reordered_translation_block_items_are_pruned_against_their_own_schema(): void
    {
        $this->actingAs($this->owner);

        Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Alpha Card',
            'slug' => 'alphaCard',
            'type' => 'nestable',
            'schema' => [
                'flag' => ['type' => 'boolean', 'name' => 'Flag'],
                'note' => [
                    'type' => 'text',
                    'name' => 'Note',
                    'translatable' => true,
                    'conditions' => [
                        'mode' => 'all',
                        'rules' => [['field' => 'flag', 'operator' => 'equals', 'value' => true]],
                    ],
                ],
            ],
            'editor' => [['header' => 'General', 'items' => ['flag', 'note']]],
        ]);

        Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Beta Card',
            'slug' => 'betaCard',
            'type' => 'nestable',
            'schema' => [
                'flag' => ['type' => 'boolean', 'name' => 'Flag'],
                'note' => ['type' => 'text', 'name' => 'Note', 'translatable' => true],
            ],
            'editor' => [['header' => 'General', 'items' => ['flag', 'note']]],
        ]);

        $compoundBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Card Page',
            'slug' => 'cardPage',
            'type' => 'root',
            'schema' => [
                'body' => ['type' => 'blocks', 'name' => 'Body'],
            ],
            'editor' => [['header' => 'General', 'items' => ['body']]],
        ]);

        $canonical = $this->createContent([
            'body' => [
                ['id' => 'item-alpha', 'block' => 'alphaCard', 'flag' => false],
                ['id' => 'item-beta', 'block' => 'betaCard', 'flag' => false, 'note' => 'Keep me'],
            ],
        ], $compoundBlock, 'en');

        $translation = $this->createContent([], $compoundBlock, 'de', $canonical);

        // Submit the items in reverse order — each must be validated and pruned
        // against its own block schema, not its positional sibling's.
        $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $translation->id,
        ]), [
            'content' => [
                'body' => [
                    ['id' => 'item-beta', 'block' => 'betaCard', 'flag' => false, 'note' => 'Übersetzt'],
                    ['id' => 'item-alpha', 'block' => 'alphaCard', 'flag' => false],
                ],
            ],
        ])->assertOk();

        $translation->refresh()->load('current_version');
        $body = $translation->current_version->content['body'];

        $this->assertSame('item-beta', $body[0]['id']);
        $this->assertSame('Übersetzt', $body[0]['note']);
        $this->assertSame('item-alpha', $body[1]['id']);
    }

    #[Test]
    public function updating_canonical_content_in_overlay_mode_validates_submitted_non_translatable_values(): void
    {
        $this->actingAs($this->owner);

        $badgeBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Badge Page',
            'slug' => 'badgePage',
            'type' => 'root',
            'schema' => [
                'variant' => [
                    'type' => 'option',
                    'name' => 'Variant',
                    'translatable' => false,
                    'options' => [
                        ['name' => 'Promo', 'value' => 'promo'],
                        ['name' => 'Frosted', 'value' => 'frosted'],
                    ],
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['variant'],
            ]],
        ]);

        // Persisted value references an option that has since been removed from the schema.
        $canonical = $this->createContent([
            'variant' => 'frosted-light',
        ], $badgeBlock, 'en');

        $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'content' => [
                'variant' => 'frosted',
            ],
        ])->assertOk();

        $canonical->refresh()->load('current_version');

        $this->assertSame('frosted', $canonical->current_version->content['variant']);
    }

    protected function createContent(
        array $contentData,
        ?Block $block = null,
        string $languageIso = 'en',
        ?Content $i18nParent = null,
    ): Content {
        $content = new Content;
        $content->forceFill([
            'block_id' => ($block ?? $this->pageBlock)->id,
            'name' => 'Page',
            'slug' => strtolower((string) Str::random(8)),
            'full_slug' => '/' . strtolower((string) Str::random(8)),
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => $contentData,
            'created_by_id' => $this->owner->id,
        ]);

        $content->current_version_id = $version->id;
        $content->save();

        return $content->fresh();
    }
}
