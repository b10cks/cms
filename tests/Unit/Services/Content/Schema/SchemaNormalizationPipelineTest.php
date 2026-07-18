<?php

namespace Tests\Unit\Services\Content\Schema;

use App\Services\Content\Schema\BlockSchema;
use App\Services\Content\Schema\SchemaNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the full request → store → serve normalization pipeline against the
 * shared fixtures in tests/fixtures/schema-normalization-pipeline.json.
 *
 * The same fixture file is asserted against the TypeScript port of this
 * pipeline in the SDK repo (packages/cli/src/schema). If a change here breaks
 * this test intentionally, regenerate the fixtures and copy them to the SDK so
 * both implementations stay in lock-step.
 */
#[CoversClass(SchemaNormalizer::class)]
#[CoversClass(BlockSchema::class)]
class SchemaNormalizationPipelineTest extends TestCase
{
    public static function fixtures(): array
    {
        $fixtures = json_decode(
            file_get_contents(__DIR__ . '/../../../../fixtures/schema-normalization-pipeline.json'),
            true
        );

        $cases = [];
        foreach ($fixtures as $fixture) {
            $cases[$fixture['name']] = [$fixture['input'], $fixture['expected'], $fixture['field_order']];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function the_pipeline_output_matches_the_shared_fixture(array $input, array $expected, array $fieldOrder)
    {
        $request = BlockSchema::fromArray($input)->toArray();
        $stored = app(SchemaNormalizer::class)->normalizeSchema($request);
        $served = BlockSchema::fromArray($stored)->toArray();

        $this->assertSame($fieldOrder, array_keys($served));
        $this->assertEquals($expected, $served);
    }

    #[Test]
    public function the_pipeline_is_idempotent_on_its_own_output()
    {
        foreach (self::fixtures() as $name => [$input, $expected]) {
            $request = BlockSchema::fromArray($expected)->toArray();
            $stored = app(SchemaNormalizer::class)->normalizeSchema($request);
            $served = BlockSchema::fromArray($stored)->toArray();

            $this->assertEquals($expected, $served, "pipeline not idempotent for fixture '{$name}'");
        }
    }
}
