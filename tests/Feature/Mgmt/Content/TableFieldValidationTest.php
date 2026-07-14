<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Services\Content\Schema\BlockSchemaRequestValidator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class TableFieldValidationTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected DataSource $dataSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');

        $this->setUpSpaceTesting($this->space);

        $this->dataSource = DataSource::withoutEvents(fn () => DataSource::factory()->create([
            'name' => 'Statuses',
            'slug' => 'statuses',
            'is_active' => true,
        ]));
    }

    #[Test]
    public function table_fields_validate_schema_configuration(): void
    {
        $validator = app(BlockSchemaRequestValidator::class);

        $validSchema = [
            'roster' => [
                'type' => 'table',
                'name' => 'Roster',
                'translatable' => true,
                'has_thead' => true,
                'min' => 1,
                'max' => 3,
                'columns' => [
                    [
                        'key' => 'name',
                        'label' => 'Name',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'status',
                        'label' => 'Status',
                        'type' => 'option',
                        'source' => 'self',
                        'options' => [
                            ['name' => 'Draft', 'value' => 'draft'],
                            ['name' => 'Review', 'value' => 'review'],
                        ],
                        'data_source_id' => null,
                    ],
                    [
                        'key' => 'active',
                        'label' => 'Active',
                        'type' => 'boolean',
                    ],
                ],
                'default' => [
                    'header' => [
                        'name' => 'Name',
                        'status' => 'Status',
                        'active' => 'Active',
                    ],
                    'rows' => [],
                ],
            ],
        ];

        $editor = [[
            'header' => 'General',
            'items' => ['roster'],
        ]];

        $this->assertSame([], $validator->validate($validSchema, $editor));

        $duplicateColumnErrors = $validator->validate([
            'roster' => [
                ...$validSchema['roster'],
                'columns' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'name', 'label' => 'Alias', 'type' => 'text'],
                ],
            ],
        ], $editor);

        $this->assertArrayHasKey('schema.roster.columns.1.key', $duplicateColumnErrors);

        $invalidDatasourceErrors = $validator->validate([
            'roster' => [
                ...$validSchema['roster'],
                'columns' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    [
                        'key' => 'status',
                        'label' => 'Status',
                        'type' => 'option',
                        'source' => 'datasource',
                        'options' => [],
                        'data_source_id' => (string) Str::ulid(),
                    ],
                ],
            ],
        ], $editor);

        $this->assertArrayHasKey('schema.roster.columns.1.data_source_id', $invalidDatasourceErrors);

        $invalidBoundsErrors = $validator->validate([
            'roster' => [
                ...$validSchema['roster'],
                'min' => 4,
                'max' => 2,
            ],
        ], $editor);

        $this->assertArrayHasKey('schema.roster.min', $invalidBoundsErrors);
    }
}
