<?php

namespace Tests\Unit\Services\Content\Schema;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\Content\Schema\ContentSchemaValidator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentSchemaValidatorTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureOptionTablesExist();
    }

    #[Test]
    public function datasource_backed_single_and_multi_options_accept_known_values_and_reject_unknown_ones(): void
    {
        $dataSource = $this->createDataSource();
        $this->createDataEntry($dataSource->id, 'published', 'Published');
        $this->createDataEntry($dataSource->id, 'featured', 'Featured');

        $block = $this->makeBlock([
            'status' => [
                'type' => 'option',
                'name' => 'Status',
                'source' => 'datasource',
                'data_source_id' => $dataSource->id,
                'default' => null,
            ],
            'tags' => [
                'type' => 'options',
                'name' => 'Tags',
                'source' => 'datasource',
                'data_source_id' => $dataSource->id,
                'default' => [],
                'min' => 1,
                'max' => 2,
            ],
        ]);

        $validResult = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'status' => 'published',
                'tags' => ['published', 'featured'],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertSame([], $validResult->errors);

        $invalidResult = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'status' => 'archived',
                'tags' => ['published', 'missing'],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertArrayHasKey('content.status', $invalidResult->errors);
        $this->assertArrayHasKey('content.tags', $invalidResult->errors);
    }

    #[Test]
    public function options_fields_enforce_minimum_and_maximum_counts(): void
    {
        $block = $this->makeBlock([
            'tags' => [
                'type' => 'options',
                'name' => 'Tags',
                'source' => 'self',
                'options' => [
                    ['name' => 'Alpha', 'value' => 'alpha'],
                    ['name' => 'Beta', 'value' => 'beta'],
                    ['name' => 'Gamma', 'value' => 'gamma'],
                ],
                'default' => [],
                'min' => 1,
                'max' => 2,
            ],
        ]);

        $tooFew = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            ['tags' => []],
            null,
            'en',
            null,
            'save',
        );

        $tooMany = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            ['tags' => ['alpha', 'beta', 'gamma']],
            null,
            'en',
            null,
            'save',
        );

        $this->assertArrayHasKey('content.tags', $tooFew->errors);
        $this->assertArrayHasKey('content.tags', $tooMany->errors);
    }

    #[Test]
    public function references_and_multi_assets_accept_empty_arrays_when_bounds_are_cleared(): void
    {
        $block = $this->makeBlock([
            'gallery' => [
                'type' => 'multi_assets',
                'name' => 'Gallery',
                'file_types' => ['all'],
                'min' => null,
                'max' => null,
            ],
            'related' => [
                'type' => 'references',
                'name' => 'Related',
                'block_whitelist' => [],
                'min' => null,
                'max' => null,
            ],
        ]);

        $result = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'gallery' => [],
                'related' => [],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertSame([], $result->errors);
    }

    protected function ensureOptionTablesExist(): void
    {
        if (! Schema::hasTable('data_sources')) {
            Schema::create('data_sources', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('external_id')->nullable();
                $table->string('name');
                $table->string('slug');
                $table->text('description')->nullable();
                $table->json('dimensions')->nullable();
                $table->json('settings')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('data_entries')) {
            Schema::create('data_entries', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('external_id')->nullable();
                $table->string('data_source_id');
                $table->string('key');
                $table->string('value')->nullable();
                $table->json('dimensions')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    protected function makeSpace(): Space
    {
        return Space::factory()->make([
            'settings' => [
                'default_language' => 'en',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function makeBlock(array $schema): Block
    {
        return new Block([
            'name' => 'Test block',
            'slug' => 'testBlock',
            'type' => 'root',
            'schema' => $schema,
            'editor' => [[
                'header' => 'General',
                'items' => array_keys($schema),
            ]],
        ]);
    }

    protected function createDataSource(): DataSource
    {
        return DataSource::withoutEvents(fn () => DataSource::query()->forceCreate([
            'id' => strtolower((string) \Str::ulid()),
            'name' => 'Statuses',
            'slug' => 'statuses',
            'description' => null,
            'dimensions' => [],
            'settings' => [],
            'is_active' => true,
        ]));
    }

    protected function createDataEntry(string $dataSourceId, string $key, ?string $value): void
    {
        DataEntry::withoutEvents(fn () => DataEntry::query()->forceCreate([
            'id' => strtolower((string) \Str::ulid()),
            'data_source_id' => $dataSourceId,
            'key' => $key,
            'value' => $value,
            'dimensions' => [],
            'is_active' => true,
        ]));
    }
}
