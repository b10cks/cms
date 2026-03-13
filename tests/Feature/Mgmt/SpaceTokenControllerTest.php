<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\Management\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpaceTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Space $space;

    protected string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user and a space
        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();

        // Attach the user to the space with owner role
        $this->assignSpaceRole($this->space, $this->user, 'owner');

        // Base URL for the token endpoints
        $this->baseUrl = "/mgmt/v1/spaces/{$this->space->id}/tokens";
    }

    #[Test]
    public function user_can_list_tokens()
    {
        // Create tokens for the space
        Token::factory()->count(3)->create([
            'space_id' => $this->space->id,
        ]);

        // Create a token for another space (shouldn't be returned)
        Token::factory()->create();

        Sanctum::actingAs($this->user);

        $response = $this->getJson($this->baseUrl);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'abilities',
                        'expires_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);
    }

    #[Test]
    public function user_can_create_token()
    {
        $tokenData = [
            'name' => 'Test API Token',
            'abilities' => ['content:read', 'content:create'],
            'expires_at' => now()->addYear()->toIso8601String(),
        ];

        Sanctum::actingAs($this->user);

        $response = $this->postJson($this->baseUrl, $tokenData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'token' => [
                    'id',
                    'name',
                    'abilities',
                    'expires_at',
                    'created_at',
                    'updated_at',
                ],
                'plain_text_token',
            ])
            ->assertJson([
                'token' => [
                    'name' => $tokenData['name'],
                ],
            ]);

        $this->assertStringStartsWith('blx_', $response->json('plain_text_token'));

        // Verify token is in the database
        $this->assertDatabaseHas('tokens', [
            'name' => $tokenData['name'],
            'space_id' => $this->space->id,
        ]);
    }

    #[Test]
    public function user_can_delete_token()
    {
        $token = Token::factory()->create([
            'space_id' => $this->space->id,
            'name' => 'Token to delete',
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("{$this->baseUrl}/{$token->id}");

        $response->assertStatus(204);

        // Verify token is deleted from the database
        $this->assertDatabaseMissing('tokens', [
            'id' => $token->id,
        ]);
    }

    #[Test]
    public function user_cannot_create_token_with_invalid_data()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson($this->baseUrl, [
            'name' => '', // Invalid: empty name
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function user_cannot_create_token_with_past_expiration_date()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson($this->baseUrl, [
            'name' => 'Test Token',
            'abilities' => ['content:read'],
            'expires_at' => now()->subDay()->toIso8601String(), // Invalid: past date
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expires_at']);
    }

    #[Test]
    public function member_user_cannot_manage_tokens()
    {
        // Create a user with member role
        $memberUser = User::factory()->create();
        $this->assignSpaceRole($this->space, $memberUser, 'member');

        // Try to list tokens
        Sanctum::actingAs($memberUser);

        $response = $this->getJson($this->baseUrl);

        $response->assertStatus(403);

        // Try to create a token
        Sanctum::actingAs($memberUser);

        $response = $this->postJson($this->baseUrl, [
            'name' => 'Test Token',
            'abilities' => ['content:read'],
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function user_cannot_delete_token_from_different_space()
    {
        // Create a space and token
        $otherSpace = Space::factory()->create();
        $otherToken = Token::factory()->create([
            'space_id' => $otherSpace->id,
        ]);

        // Try to delete the token
        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("{$this->baseUrl}/{$otherToken->id}");

        $response->assertStatus(403);

        // Verify token is still in the database
        $this->assertDatabaseHas('tokens', [
            'id' => $otherToken->id,
        ]);
    }

    #[Test]
    public function user_can_filter_tokens_by_name()
    {
        // Create tokens with specific names
        Token::factory()->create([
            'space_id' => $this->space->id,
            'name' => 'Alpha Token',
        ]);

        Token::factory()->create([
            'space_id' => $this->space->id,
            'name' => 'Beta Token',
        ]);

        Token::factory()->create([
            'space_id' => $this->space->id,
            'name' => 'Alpha Prime',
        ]);

        // Filter by name containing 'Alpha'
        Sanctum::actingAs($this->user);

        $response = $this->getJson("{$this->baseUrl}?name=Alpha");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Verify only tokens with 'Alpha' in the name are returned
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Alpha Token', $names);
        $this->assertContains('Alpha Prime', $names);
        $this->assertNotContains('Beta Token', $names);
    }

    #[Test]
    public function user_can_filter_tokens_by_abilities()
    {
        // Create tokens with specific abilities
        Token::factory()->create([
            'space_id' => $this->space->id,
            'name' => 'Read Token',
            'abilities' => ['content:read'],
        ]);

        Token::factory()->create([
            'space_id' => $this->space->id,
            'name' => 'Full Token',
            'abilities' => ['content:read', 'content:create', 'content:update', 'content:delete'],
        ]);

        // Filter by ability
        Sanctum::actingAs($this->user);

        $response = $this->getJson("{$this->baseUrl}?abilities=content:create");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Verify only tokens with the 'content:create' ability are returned
        $this->assertEquals('Full Token', $response->json('data.0.name'));
    }
}
