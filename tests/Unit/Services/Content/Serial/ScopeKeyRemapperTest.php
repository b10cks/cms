<?php

namespace Tests\Unit\Services\Content\Serial;

use App\Services\Content\Serial\ScopeKeyRemapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ScopeKeyRemapper::class)]
class ScopeKeyRemapperTest extends TestCase
{
    protected ScopeKeyRemapper $remapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->remapper = new ScopeKeyRemapper;
    }

    #[Test]
    public function it_translates_block_and_parent_ids(): void
    {
        $this->assertSame(
            'blk:tgt-block|par:tgt-parent',
            $this->remapper->remap(
                'blk:src-block|par:src-parent',
                ['src-block' => 'tgt-block'],
                ['src-parent' => 'tgt-parent'],
            ),
        );
    }

    #[Test]
    public function it_leaves_id_free_dimensions_alone(): void
    {
        $this->assertSame(
            'sp|blk:tgt|par:-|lng:de|y:2026|m:2026-07',
            $this->remapper->remap('sp|blk:src|par:-|lng:de|y:2026|m:2026-07', ['src' => 'tgt'], []),
        );
    }

    #[Test]
    public function an_unmapped_block_makes_the_key_untranslatable(): void
    {
        // Returning the key unchanged would point it at a block in the source
        // space; dropping the segment would merge two scopes into one and hand
        // out duplicate numbers. Neither is acceptable, so it fails instead.
        $this->assertNull($this->remapper->remap('blk:unknown|par:-', [], []));
    }

    #[Test]
    public function an_unmapped_parent_makes_the_key_untranslatable(): void
    {
        $this->assertNull(
            $this->remapper->remap('blk:src|par:missing', ['src' => 'tgt'], []),
        );
    }

    #[Test]
    public function it_is_stable_for_keys_that_need_no_translation(): void
    {
        $this->assertSame('sp', $this->remapper->remap('sp', [], []));
    }

    #[Test]
    public function two_source_scopes_never_collapse_into_one_target_scope(): void
    {
        $blockMap = ['block-a' => 'tgt-a', 'block-b' => 'tgt-b'];

        $this->assertNotSame(
            $this->remapper->remap('blk:block-a|par:-', $blockMap, []),
            $this->remapper->remap('blk:block-b|par:-', $blockMap, []),
        );
    }
}
