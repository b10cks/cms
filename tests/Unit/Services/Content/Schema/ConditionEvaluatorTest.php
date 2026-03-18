<?php

namespace Tests\Unit\Services\Content\Schema;

use App\Services\Content\Schema\BlockSchema;
use App\Services\Content\Schema\ConditionEvaluator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    #[Test]
    public function it_supports_all_mode_conditions(): void
    {
        $schema = BlockSchema::fromArray([
            'status' => [
                'type' => 'option',
                'options' => [
                    ['name' => 'Draft', 'value' => 'draft'],
                    ['name' => 'Published', 'value' => 'published'],
                ],
            ],
            'featured' => [
                'type' => 'boolean',
            ],
            'headline' => [
                'type' => 'text',
                'conditions' => [
                    'mode' => 'all',
                    'rules' => [
                        ['field' => 'status', 'operator' => 'equals', 'value' => 'published'],
                        ['field' => 'featured', 'operator' => 'equals', 'value' => true],
                    ],
                ],
            ],
        ]);

        $field = $schema->getField('headline');
        $evaluator = app(ConditionEvaluator::class);

        $this->assertTrue($evaluator->isVisible($field, $schema, [
            'status' => 'published',
            'featured' => true,
        ], [
            'status' => 'published',
            'featured' => true,
        ]));

        $this->assertFalse($evaluator->isVisible($field, $schema, [
            'status' => 'published',
            'featured' => false,
        ], [
            'status' => 'published',
            'featured' => false,
        ]));
    }

    #[Test]
    public function it_supports_any_mode_conditions(): void
    {
        $schema = BlockSchema::fromArray([
            'status' => ['type' => 'option'],
            'flag' => ['type' => 'boolean'],
            'headline' => [
                'type' => 'text',
                'conditions' => [
                    'mode' => 'any',
                    'rules' => [
                        ['field' => 'status', 'operator' => 'equals', 'value' => 'published'],
                        ['field' => 'flag', 'operator' => 'equals', 'value' => true],
                    ],
                ],
            ],
        ]);

        $field = $schema->getField('headline');
        $evaluator = app(ConditionEvaluator::class);

        $this->assertTrue($evaluator->isVisible($field, $schema, [
            'status' => 'draft',
            'flag' => true,
        ], [
            'status' => 'draft',
            'flag' => true,
        ]));
    }
}
