<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\FieldPlugin;
use App\Models\User;
use App\Services\Content\Schema\BlockSchemaRequestValidator;
use App\Services\Content\Schema\SchemaNormalizer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class PluginFieldValidationTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected FieldPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');

        $this->setUpSpaceTesting($this->space);

        $this->plugin = FieldPlugin::withoutEvents(
            fn () => FieldPlugin::factory()->published()->create(['handle' => 'product-picker'])
        );
    }

    protected function schema(array $overrides = []): array
    {
        return [
            'product' => array_merge([
                'type' => 'plugin',
                'name' => 'Product',
                'plugin_handle' => 'product-picker',
                'options' => ['api_base' => 'https://shop.example'],
            ], $overrides),
        ];
    }

    protected function editor(): array
    {
        return [['header' => 'General', 'items' => ['product']]];
    }

    #[Test]
    public function plugin_fields_validate_schema_configuration(): void
    {
        $validator = app(BlockSchemaRequestValidator::class);

        $this->assertSame([], $validator->validate($this->schema(), $this->editor()));
    }

    #[Test]
    public function unknown_plugin_handle_is_rejected(): void
    {
        $validator = app(BlockSchemaRequestValidator::class);

        $errors = $validator->validate($this->schema(['plugin_handle' => 'missing-plugin']), $this->editor());
        $this->assertArrayHasKey('schema.product.plugin_handle', $errors);

        $errors = $validator->validate($this->schema(['plugin_handle' => '']), $this->editor());
        $this->assertArrayHasKey('schema.product.plugin_handle', $errors);
    }

    #[Test]
    public function non_string_option_values_are_normalized_away(): void
    {
        $validator = app(BlockSchemaRequestValidator::class);

        // Scalars are coerced to strings, non-scalars are dropped — either way
        // the normalized schema passes validation with string-only options.
        $this->assertSame([], $validator->validate(
            $this->schema(['options' => ['limit' => 5, 'nested' => ['not' => 'allowed']]]),
            $this->editor()
        ));

        $normalized = app(SchemaNormalizer::class)->normalizeField('product', [
            'type' => 'plugin',
            'plugin_handle' => 'product-picker',
            'options' => ['limit' => 5, 'nested' => ['not' => 'allowed']],
        ]);

        $this->assertSame(['limit' => '5'], $normalized['options']);
    }

    #[Test]
    public function normalizer_keeps_plugin_config_and_defaults(): void
    {
        $normalizer = app(SchemaNormalizer::class);

        $normalized = $normalizer->normalizeField('product', [
            'type' => 'plugin',
            'name' => 'Product',
            'plugin_handle' => 'product-picker',
            'options' => ['api_base' => 'https://shop.example', 'limit' => 5],
        ]);

        $this->assertSame('plugin', $normalized['type']);
        $this->assertSame('product-picker', $normalized['plugin_handle']);
        // Scalar option values are coerced to strings.
        $this->assertSame(['api_base' => 'https://shop.example', 'limit' => '5'], $normalized['options']);
        $this->assertFalse($normalized['indexable']);
    }

    #[Test]
    public function plugin_fields_can_be_translatable_but_not_indexable(): void
    {
        $normalizer = app(SchemaNormalizer::class);

        $this->assertTrue($normalizer->supportsTranslation('plugin'));
        $this->assertFalse($normalizer->supportsIndexing('plugin'));

        $normalized = $normalizer->normalizeField('product', [
            'type' => 'plugin',
            'plugin_handle' => 'product-picker',
            'translatable' => true,
        ]);

        $this->assertTrue($normalized['translatable']);
    }
}
