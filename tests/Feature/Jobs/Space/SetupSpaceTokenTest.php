<?php

namespace Tests\Feature\Jobs\Space;

use App\Jobs\Space\SetupSpace;
use App\Models\Management\Space;
use App\Models\Management\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers SetupSpace::createDefaultToken() in isolation. The job's other steps
 * provision real storage and a space database, which is far more than this
 * behaviour needs, so the step is exercised through a subclass rather than by
 * running execute().
 */
class SetupSpaceTokenTest extends TestCase
{
    use RefreshDatabase;

    private function setupTokenFor(Space $space): void
    {
        $job = new class ($space) extends SetupSpace {
            public function runCreateDefaultToken(): void
            {
                $this->createDefaultToken($this->space);
            }
        };

        $job->runCreateDefaultToken();
    }

    #[Test]
    public function it_creates_a_read_only_default_token()
    {
        $space = Space::factory()->create();

        $this->setupTokenFor($space);

        $token = $space->tokens()->sole();

        $this->assertSame('Default', $token->name);
        $this->assertSame(['*:read'], $token->abilities->toArray());
        $this->assertStringStartsWith('blx_', $token->token);
    }

    #[Test]
    public function the_default_token_can_read_but_not_write()
    {
        $space = Space::factory()->create();

        $this->setupTokenFor($space);

        $token = $space->tokens()->sole();

        $this->assertTrue($token->hasAbility('contents', 'read'));
        $this->assertFalse($token->hasAbility('contents', 'update'));
        $this->assertFalse($token->hasAbility('contents', 'delete'));
    }

    #[Test]
    public function it_does_not_create_a_second_token_when_one_exists()
    {
        $space = Space::factory()->create();
        Token::factory()->create(['space_id' => $space->id]);

        $this->setupTokenFor($space);

        $this->assertSame(1, $space->tokens()->count());
    }
}
