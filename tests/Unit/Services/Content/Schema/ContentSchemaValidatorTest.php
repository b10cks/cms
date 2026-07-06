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

    #[Test]
    public function table_fields_validate_rows_ids_and_cell_types(): void
    {
        $block = $this->makeBlock([
            'roster' => [
                'type' => 'table',
                'name' => 'Roster',
                'translatable' => true,
                'has_thead' => true,
                'min' => 1,
                'max' => 2,
                'columns' => [
                    [
                        'key' => 'name',
                        'label' => 'Name',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'count',
                        'label' => 'Count',
                        'type' => 'number',
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
                        'count' => 'Count',
                        'status' => 'Status',
                        'active' => 'Active',
                    ],
                    'rows' => [],
                ],
            ],
        ]);

        $validResult = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'roster' => [
                    'header' => [
                        'name' => 'Name',
                        'count' => 'Count',
                        'status' => 'Status',
                        'active' => 'Active',
                    ],
                    'rows' => [
                        [
                            'id' => 'row-a',
                            'cells' => [
                                'name' => 'Alice',
                                'count' => 12,
                                'status' => 'draft',
                                'active' => true,
                            ],
                        ],
                    ],
                ],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertSame([], $validResult->errors);

        $invalidRowCount = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'roster' => [
                    'header' => [],
                    'rows' => [],
                ],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertArrayHasKey('content.roster', $invalidRowCount->errors);

        $invalidRowIds = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'roster' => [
                    'header' => [],
                    'rows' => [
                        [
                            'id' => 'row-a',
                            'cells' => ['name' => 'Alice', 'count' => 1, 'status' => 'draft', 'active' => true],
                        ],
                        [
                            'id' => 'row-a',
                            'cells' => ['name' => 'Bob', 'count' => 2, 'status' => 'review', 'active' => false],
                        ],
                    ],
                ],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertArrayHasKey('content.roster', $invalidRowIds->errors);

        $invalidOption = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'roster' => [
                    'header' => [],
                    'rows' => [
                        [
                            'id' => 'row-a',
                            'cells' => [
                                'name' => 'Alice',
                                'count' => 1,
                                'status' => 'missing',
                                'active' => true,
                            ],
                        ],
                    ],
                ],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertArrayHasKey('content.roster', $invalidOption->errors);

        $invalidTypes = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'roster' => [
                    'header' => [],
                    'rows' => [
                        [
                            'id' => 'row-a',
                            'cells' => [
                                'name' => false,
                                'count' => 1,
                                'status' => 'draft',
                                'active' => 'yes',
                            ],
                        ],
                    ],
                ],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertArrayHasKey('content.roster', $invalidTypes->errors);
    }

    #[Test]
    public function email_links_accept_optional_mailto_fields_when_their_values_are_valid(): void
    {
        $block = $this->makeBlock([
            'cta' => [
                'type' => 'link',
                'name' => 'CTA',
                'translatable' => true,
                'asset_link_type' => false,
                'email_link_type' => true,
                'allow_target_blank' => true,
            ],
        ]);

        $result = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'cta' => [
                    'type' => 'email',
                    'email' => 'hello@example.com',
                    'subject' => 'Need help',
                    'body' => "Hi there,\nI have a question.",
                    'cc' => 'copy@example.com, second@example.com',
                    'bcc' => 'hidden@example.com',
                ],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function url_links_accept_protocol_based_targets_like_tel_links(): void
    {
        $block = $this->makeBlock([
            'cta' => [
                'type' => 'link',
                'name' => 'CTA',
                'translatable' => true,
                'asset_link_type' => false,
                'email_link_type' => true,
                'allow_target_blank' => true,
            ],
        ]);

        $result = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'cta' => [
                    'type' => 'url',
                    'url' => 'tel:+436999',
                ],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function email_links_reject_invalid_cc_and_non_string_subject_values(): void
    {
        $block = $this->makeBlock([
            'cta' => [
                'type' => 'link',
                'name' => 'CTA',
                'translatable' => true,
                'asset_link_type' => false,
                'email_link_type' => true,
                'allow_target_blank' => true,
            ],
        ]);

        $result = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            [
                'cta' => [
                    'type' => 'email',
                    'email' => 'hello@example.com',
                    'subject' => ['invalid'],
                    'cc' => 'valid@example.com, not-an-email',
                ],
            ],
            null,
            'en',
            null,
            'save',
        );

        $this->assertArrayHasKey('content.cta', $result->errors);
        $this->assertSame(
            [
                'CTA subject must be a string.',
                'CTA CC must contain valid email addresses.',
            ],
            $result->errors['content.cta'],
        );
    }

    #[Test]
    public function geo_fields_accept_valid_points_and_reject_out_of_range_coordinates(): void
    {
        $block = $this->makeBlock([
            'location' => [
                'type' => 'geo',
                'name' => 'Location',
                'key_style' => 'lat_lng',
                'altitude' => false,
            ],
        ]);

        $valid = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            ['location' => ['lat' => 48.2, 'lng' => 16.37]],
            null,
            'en',
            null,
            'save',
        );

        $this->assertSame([], $valid->errors);

        $outOfRange = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            ['location' => ['lat' => 200, 'lng' => 16.37]],
            null,
            'en',
            null,
            'save',
        );

        $this->assertArrayHasKey('content.location', $outOfRange->errors);
    }

    #[Test]
    public function geo_fields_read_coordinates_using_the_configured_key_style(): void
    {
        $block = $this->makeBlock([
            'location' => [
                'type' => 'geo',
                'name' => 'Location',
                'key_style' => 'latitude_longitude',
                'altitude' => false,
            ],
        ]);

        $matchingKeys = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            ['location' => ['latitude' => 48.2, 'longitude' => 16.37]],
            null,
            'en',
            null,
            'save',
        );

        $this->assertSame([], $matchingKeys->errors);

        // Keys from a different style are not recognised for this field's configuration.
        $mismatchedKeys = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            ['location' => ['lat' => 48.2, 'lng' => 16.37]],
            null,
            'en',
            null,
            'save',
        );

        $this->assertArrayHasKey('content.location', $mismatchedKeys->errors);
    }

    #[Test]
    public function geo_fields_require_altitude_only_when_required_and_publishing(): void
    {
        $block = $this->makeBlock([
            'location' => [
                'type' => 'geo',
                'name' => 'Location',
                'key_style' => 'lat_lng',
                'altitude' => true,
                'required' => true,
            ],
        ]);

        $blankAltitude = ['location' => ['lat' => 48.2, 'lng' => 16.37, 'alt' => null]];

        $saveResult = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            $blankAltitude,
            null,
            'en',
            null,
            'save',
        );

        $this->assertSame([], $saveResult->errors);

        $publishResult = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            $blankAltitude,
            null,
            'en',
            null,
            'publish',
        );

        $this->assertArrayHasKey('content.location', $publishResult->errors);

        $completeResult = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            ['location' => ['lat' => 48.2, 'lng' => 16.37, 'alt' => 190]],
            null,
            'en',
            null,
            'publish',
        );

        $this->assertSame([], $completeResult->errors);
    }

    #[Test]
    public function text_patterns_are_enforced_with_and_without_preg_delimiters(): void
    {
        $block = $this->makeBlock([
            'code' => [
                'type' => 'text',
                'name' => 'Code',
                'validation' => ['pattern' => '^[a-z]{3}$'],
            ],
            'slug' => [
                'type' => 'text',
                'name' => 'Slug',
                'validation' => ['pattern' => '/^[a-z-]+$/'],
            ],
        ]);

        $validResult = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            ['code' => 'abc', 'slug' => 'my-slug'],
            null,
            'en',
            null,
            'save',
        );

        $this->assertSame([], $validResult->errors);

        $invalidResult = app(ContentSchemaValidator::class)->validateSubmission(
            $this->makeSpace(),
            $block,
            ['code' => 'ABC-123', 'slug' => 'My Slug!'],
            null,
            'en',
            null,
            'save',
        );

        $this->assertArrayHasKey('content.code', $invalidResult->errors);
        $this->assertArrayHasKey('content.slug', $invalidResult->errors);
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
