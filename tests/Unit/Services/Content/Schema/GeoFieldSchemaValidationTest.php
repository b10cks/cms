<?php

namespace Tests\Unit\Services\Content\Schema;

use App\Services\Content\Schema\BlockSchemaRequestValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeoFieldSchemaValidationTest extends TestCase
{
    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    protected function geoSchema(array $overrides = []): array
    {
        $schema = [
            'location' => [
                'type' => 'geo',
                'name' => 'Location',
                'key_style' => 'lat_lng',
                'altitude' => false,
                'map' => true,
                ...$overrides,
            ],
        ];

        $editor = [[
            'header' => 'General',
            'items' => ['location'],
        ]];

        return [$schema, $editor];
    }

    #[Test]
    public function geo_is_a_supported_field_type(): void
    {
        [$schema, $editor] = $this->geoSchema();

        $this->assertSame([], app(BlockSchemaRequestValidator::class)->validate($schema, $editor));
    }

    #[Test]
    public function geo_rejects_an_unknown_key_style(): void
    {
        [$schema, $editor] = $this->geoSchema(['key_style' => 'utm']);

        $errors = app(BlockSchemaRequestValidator::class)->validate($schema, $editor);

        $this->assertArrayHasKey('schema.location.key_style', $errors);
    }

    #[Test]
    public function geo_rejects_non_boolean_altitude_and_map_toggles(): void
    {
        [$schema, $editor] = $this->geoSchema(['altitude' => 'yes', 'map' => 'no']);

        $errors = app(BlockSchemaRequestValidator::class)->validate($schema, $editor);

        $this->assertArrayHasKey('schema.location.altitude', $errors);
        $this->assertArrayHasKey('schema.location.map', $errors);
    }

    #[Test]
    public function geo_cannot_be_marked_indexable(): void
    {
        [$schema, $editor] = $this->geoSchema(['indexable' => true]);

        $errors = app(BlockSchemaRequestValidator::class)->validate($schema, $editor);

        $this->assertArrayHasKey('schema.location.indexable', $errors);
    }
}
