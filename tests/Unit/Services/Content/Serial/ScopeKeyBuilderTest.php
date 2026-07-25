<?php

namespace Tests\Unit\Services\Content\Serial;

use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Content\Serial\ScopeKeyBuilder;
use App\Services\Content\Serial\SerialContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ScopeKeyBuilder::class)]
class ScopeKeyBuilderTest extends TestCase
{
    protected function context(?string $parentId = null, ?Carbon $createdAt = null): SerialContext
    {
        $block = new Block(['name' => 'House', 'slug' => 'house']);
        $block->id = 'blk1';

        $parent = null;

        if ($parentId !== null) {
            $parent = new Content(['name' => 'Category']);
            $parent->id = $parentId;
        }

        return new SerialContext(
            block: $block,
            parent: $parent,
            languageIso: 'de',
            createdAt: $createdAt,
        );
    }

    #[Test]
    public function it_emits_dimensions_in_a_canonical_order(): void
    {
        $builder = new ScopeKeyBuilder;
        $context = $this->context('par1');

        // Two equivalent scopes must describe the same counter, otherwise
        // reordering the config in the designer silently restarts numbering.
        $this->assertSame(
            $builder->scopeKey(['block', 'parent'], $context),
            $builder->scopeKey(['parent', 'block'], $context),
        );
    }

    #[Test]
    public function it_renders_each_dimension(): void
    {
        $builder = new ScopeKeyBuilder;
        $context = $this->context('par1', Carbon::parse('2026-07-26'));

        $this->assertSame(
            'sp|blk:blk1|par:par1|lng:de|y:2026|m:2026-07',
            $builder->scopeKey(['space', 'block', 'parent', 'language', 'year', 'month'], $context),
        );
    }

    #[Test]
    public function a_root_entry_gets_a_stable_placeholder_parent(): void
    {
        $builder = new ScopeKeyBuilder;

        $this->assertSame('blk:blk1|par:-', $builder->scopeKey(['block', 'parent'], $this->context()));
    }

    #[Test]
    public function an_empty_scope_falls_back_to_the_default(): void
    {
        $builder = new ScopeKeyBuilder;
        $context = $this->context('par1');

        $this->assertSame(
            $builder->scopeKey(ScopeKeyBuilder::DEFAULT_SCOPE, $context),
            $builder->scopeKey([], $context),
        );
    }

    #[Test]
    public function unknown_dimensions_are_ignored_rather_than_fatal(): void
    {
        $builder = new ScopeKeyBuilder;

        $this->assertSame(
            'blk:blk1',
            $builder->scopeKey(['block', 'galaxy'], $this->context('par1')),
        );
    }

    #[Test]
    public function uniqueness_modes_widen_the_key_independently_of_the_counter(): void
    {
        $builder = new ScopeKeyBuilder;
        $context = $this->context('par1');
        $scope = ['block', 'parent'];

        $this->assertSame('blk:blk1|par:par1', $builder->uniqueKey('scope', $scope, $context));
        $this->assertSame('blk:blk1', $builder->uniqueKey('block', $scope, $context));
        $this->assertSame('sp', $builder->uniqueKey('space', $scope, $context));
        $this->assertNull(
            $builder->uniqueKey('none', $scope, $context),
            'No uniqueness must be a null key so the unique index stops applying.',
        );
    }
}
