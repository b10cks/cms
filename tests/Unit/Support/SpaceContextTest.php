<?php

namespace Tests\Unit\Support;

use App\Models\Management\Space;
use App\Support\SpaceContext;
use Tests\TestCase;

class SpaceContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->offsetUnset('currentSpace');
    }

    private function space(string $id): Space
    {
        return (new Space)->forceFill(['id' => $id]);
    }

    public function test_current_is_null_when_nothing_is_bound(): void
    {
        $this->assertNull(SpaceContext::current());
    }

    public function test_enter_binds_the_space_and_restore_unbinds_it(): void
    {
        $space = $this->space('space-a');

        $restore = SpaceContext::enter($space);

        $this->assertSame($space, SpaceContext::current());
        $this->assertSame($space, app('currentSpace'));

        $restore();

        $this->assertFalse(app()->bound('currentSpace'));
        $this->assertNull(SpaceContext::current());
    }

    public function test_restore_puts_back_a_previously_bound_space(): void
    {
        $prior = $this->space('space-prior');
        $next = $this->space('space-next');

        app()->offsetSet('currentSpace', $prior);

        $restore = SpaceContext::enter($next);
        $this->assertSame($next, SpaceContext::current());

        $restore();
        $this->assertSame($prior, SpaceContext::current());
    }

    public function test_nested_enters_unwind_in_order(): void
    {
        $a = $this->space('space-a');
        $b = $this->space('space-b');
        $c = $this->space('space-c');

        $restoreA = SpaceContext::enter($a);
        $restoreB = SpaceContext::enter($b);
        $restoreC = SpaceContext::enter($c);

        $this->assertSame($c, SpaceContext::current());

        $restoreC();
        $this->assertSame($b, SpaceContext::current());

        $restoreB();
        $this->assertSame($a, SpaceContext::current());

        $restoreA();
        $this->assertFalse(app()->bound('currentSpace'));
    }

    public function test_restore_runs_even_when_the_body_throws(): void
    {
        $space = $this->space('space-a');
        $restore = SpaceContext::enter($space);

        try {
            try {
                throw new \RuntimeException('boom');
            } finally {
                $restore();
            }
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(app()->bound('currentSpace'));
    }

    public function test_current_ignores_a_non_space_binding(): void
    {
        app()->offsetSet('currentSpace', 'not-a-space');

        $this->assertNull(SpaceContext::current());
    }
}
