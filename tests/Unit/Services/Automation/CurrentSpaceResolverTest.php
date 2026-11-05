<?php

namespace Tests\Unit\Services\Automation;

use App\Models\Management\Space;
use App\Services\Automation\CurrentSpaceResolver;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CurrentSpaceResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function request_input_cannot_point_automations_at_another_space(): void
    {
        $current = Space::factory()->create();
        $other = Space::factory()->create();
        $this->app->instance('request', Request::create('/', 'GET', ['space' => $other->id]));
        $restore = SpaceContext::enter($current);

        try {
            $this->assertSame($current->id, app(CurrentSpaceResolver::class)->resolve()?->id);
        } finally {
            $restore();
        }
    }
}
