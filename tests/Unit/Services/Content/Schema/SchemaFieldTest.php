<?php

namespace Tests\Unit\Services\Content\Schema;

use App\Services\Content\Schema\SchemaField;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchemaFieldTest extends TestCase
{
    #[Test]
    public function it_normalizes_legacy_schema_attributes(): void
    {
        $field = new SchemaField('heroItems', [
            'type' => 'multiAsset',
            'label' => 'Hero Items',
            'dependencies' => [
                ['field' => 'status', 'operator' => '=', 'value' => 'published'],
            ],
            'minimum' => 2,
        ]);

        $this->assertSame('multi_assets', $field->getType());
        $this->assertSame('Hero Items', $field->getLabel());
        $this->assertSame('equals', $field->getConditions()['rules'][0]['operator']);
        $this->assertSame(2, $field->getValidationValue('min'));
        $this->assertFalse($field->isIndexable());
    }

    #[Test]
    public function it_defaults_text_fields_to_indexable_and_non_text_fields_to_not_indexable(): void
    {
        $text = new SchemaField('title', [
            'type' => 'text',
        ]);
        $asset = new SchemaField('image', [
            'type' => 'asset',
        ]);

        $this->assertTrue($text->isIndexable());
        $this->assertFalse($asset->isIndexable());
    }

    #[Test]
    public function it_defaults_option_sources_to_self_and_null_datasource(): void
    {
        $field = new SchemaField('status', [
            'type' => 'option',
            'options' => [
                ['name' => 'Draft', 'value' => 'draft'],
            ],
        ]);

        $this->assertSame('self', $field->getAttribute('source'));
        $this->assertNull($field->getAttribute('data_source_id'));
    }
}
