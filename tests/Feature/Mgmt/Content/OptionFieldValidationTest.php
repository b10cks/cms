<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Services\Content\Schema\BlockSchemaRequestValidator;
use App\Services\Content\Schema\ContentSchemaValidator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OptionFieldValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected User $owner;

    protected Space $space;

    protected DataSource $dataSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');

        if (! Schema::hasTable('data_sources')) {
            if (Schema::hasTable('audit_logs')) {
                Schema::drop('audit_logs');
            }

            $this->artisan('migrate', [
                '--path' => 'database/migrations/spaces',
                '--realpath' => true,
            ]);
        }

        app()->instance('currentSpace', $this->space);

        $this->dataSource = DataSource::withoutEvents(fn () => DataSource::factory()->create([
            'name' => 'Statuses',
            'slug' => 'statuses',
            'is_active' => true,
        ]));

        DataEntry::withoutEvents(fn () => DataEntry::factory()->create([
            'data_source_id' => $this->dataSource->id,
            'key' => 'draft',
            'value' => 'Draft',
            'is_active' => true,
        ]));

        DataEntry::withoutEvents(fn () => DataEntry::factory()->create([
            'data_source_id' => $this->dataSource->id,
            'key' => 'review',
            'value' => 'In Review',
            'is_active' => true,
        ]));
    }

    #[Test]
    public function datasource_backed_option_fields_validate_schema_defaults_and_content_values(): void
    {
        $requestValidator = app(BlockSchemaRequestValidator::class);

        $validSchema = [
            'status' => [
                'type' => 'option',
                'name' => 'Status',
                'source' => 'datasource',
                'data_source_id' => $this->dataSource->id,
                'default' => 'draft',
                'options' => [],
            ],
            'tags' => [
                'type' => 'options',
                'name' => 'Tags',
                'source' => 'datasource',
                'data_source_id' => $this->dataSource->id,
                'default' => ['draft'],
                'min' => null,
                'max' => null,
                'options' => [],
            ],
            'related' => [
                'type' => 'references',
                'name' => 'Related',
                'block_whitelist' => [],
                'min' => null,
                'max' => null,
                'default' => [],
            ],
            'gallery' => [
                'type' => 'multi_assets',
                'name' => 'Gallery',
                'file_types' => ['all'],
                'min' => null,
                'max' => null,
                'default' => [],
            ],
        ];

        $editor = [[
            'header' => 'General',
            'items' => ['status', 'tags', 'related', 'gallery'],
        ]];

        $this->assertSame([], $requestValidator->validate($validSchema, $editor));

        $invalidDataSourceErrors = $requestValidator->validate([
            'status' => [
                'type' => 'option',
                'name' => 'Status',
                'source' => 'datasource',
                'data_source_id' => (string) Str::ulid(),
                'default' => 'draft',
                'options' => [],
            ],
        ], [[
            'header' => 'General',
            'items' => ['status'],
        ]]);

        $this->assertArrayHasKey('schema.status.data_source_id', $invalidDataSourceErrors);

        $invalidDefaultErrors = $requestValidator->validate([
            'status' => [
                'type' => 'option',
                'name' => 'Status',
                'source' => 'datasource',
                'data_source_id' => $this->dataSource->id,
                'default' => 'missing',
                'options' => [],
            ],
            'tags' => [
                'type' => 'options',
                'name' => 'Tags',
                'source' => 'datasource',
                'data_source_id' => $this->dataSource->id,
                'default' => ['draft', 'missing'],
                'min' => 3,
                'max' => 1,
                'options' => [],
            ],
        ], [[
            'header' => 'General',
            'items' => ['status', 'tags'],
        ]]);

        $this->assertArrayHasKey('schema.status.default', $invalidDefaultErrors);
        $this->assertArrayHasKey('schema.tags.default.1', $invalidDefaultErrors);
        $this->assertArrayHasKey('schema.tags.min', $invalidDefaultErrors);

        $block = Block::withoutEvents(fn () => Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Article',
            'slug' => 'article',
            'type' => 'root',
            'schema' => [
                'status' => [
                    'type' => 'option',
                    'name' => 'Status',
                    'source' => 'datasource',
                    'data_source_id' => $this->dataSource->id,
                    'default' => null,
                    'options' => [],
                ],
                'tags' => [
                    'type' => 'options',
                    'name' => 'Tags',
                    'source' => 'datasource',
                    'data_source_id' => $this->dataSource->id,
                    'default' => [],
                    'min' => 1,
                    'max' => 2,
                    'options' => [],
                ],
                'related' => [
                    'type' => 'references',
                    'name' => 'Related',
                    'block_whitelist' => [],
                    'min' => null,
                    'max' => null,
                    'default' => [],
                ],
                'gallery' => [
                    'type' => 'multi_assets',
                    'name' => 'Gallery',
                    'file_types' => ['all'],
                    'min' => null,
                    'max' => null,
                    'default' => [],
                ],
            ],
            'editor' => $editor,
        ]));

        $contentValidator = app(ContentSchemaValidator::class);

        $validResult = $contentValidator->validateSubmission($this->space, $block, [
            'status' => 'draft',
            'tags' => ['draft', 'review'],
            'related' => [],
            'gallery' => [],
        ]);

        $this->assertSame([], $validResult->errors);

        $invalidValueResult = $contentValidator->validateSubmission($this->space, $block, [
            'status' => 'missing',
            'tags' => ['draft', 'unknown'],
            'related' => [],
            'gallery' => [],
        ]);

        $this->assertArrayHasKey('content.status', $invalidValueResult->errors);
        $this->assertArrayHasKey('content.tags', $invalidValueResult->errors);

        $invalidCountResult = $contentValidator->validateSubmission($this->space, $block, [
            'status' => 'draft',
            'tags' => [],
            'related' => [],
            'gallery' => [],
        ]);

        $this->assertArrayHasKey('content.tags', $invalidCountResult->errors);

        $tooManyResult = $contentValidator->validateSubmission($this->space, $block, [
            'status' => 'draft',
            'tags' => ['draft', 'review', 'draft'],
            'related' => [],
            'gallery' => [],
        ]);

        $this->assertArrayHasKey('content.tags', $tooManyResult->errors);
    }
}
