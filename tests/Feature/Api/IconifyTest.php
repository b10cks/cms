<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\IconifyController;
use App\Models\Management\Space;
use App\Models\Management\Token;
use App\Models\Space\Icon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(IconifyController::class)]
class IconifyTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Token $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->withLive()->create(['slug' => 'design-system']);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'iconify-token',
            'expires_at' => null,
        ]);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        Icon::factory()->create(['key' => 'home', 'name' => 'Home', 'body' => '<path d="M1 1"/>', 'width' => 24, 'height' => 24]);
        Icon::factory()->create(['key' => 'arrow', 'name' => 'Arrow', 'body' => '<path d="M2 2"/>', 'width' => 16, 'height' => 16]);
    }

    private function url(string $suffix): string
    {
        return "/api/v1/iconify/{$this->space->id}{$suffix}";
    }

    #[Test]
    public function icon_data_returns_iconify_json_with_requested_icons(): void
    {
        $response = $this->getJson($this->url('/b10cks.json?token=iconify-token&icons=home,missing'));

        $response->assertOk();
        $response->assertJsonPath('prefix', 'b10cks');
        $response->assertJsonPath('icons.home.body', '<path d="M1 1"/>');
        $response->assertJsonPath('icons.home.width', 24);
        $response->assertJsonPath('not_found', ['missing']);
        $this->assertIsInt($response->json('lastModified'));
        // Only the requested (existing) icon is present.
        $this->assertArrayNotHasKey('arrow', $response->json('icons'));
    }

    #[Test]
    public function icon_data_returns_full_set_without_icons_param(): void
    {
        $response = $this->getJson($this->url('/b10cks.json?token=iconify-token'));

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['home', 'arrow'], array_keys($response->json('icons')));
    }

    #[Test]
    public function collections_reports_the_space_prefix_and_count(): void
    {
        $response = $this->getJson($this->url('/collections?token=iconify-token'));

        $response->assertOk();
        $response->assertJsonPath('b10cks.name', $this->space->name);
        $response->assertJsonPath('b10cks.total', 2);
    }

    #[Test]
    public function last_modified_returns_a_timestamp_for_the_prefix(): void
    {
        $response = $this->getJson($this->url('/last-modified?token=iconify-token'));

        $response->assertOk();
        $this->assertIsInt($response->json('lastModified.b10cks'));
    }

    #[Test]
    public function search_returns_prefixed_keys(): void
    {
        $response = $this->getJson($this->url('/search?token=iconify-token&query=home'));

        $response->assertOk();
        $response->assertJsonPath('icons', ['b10cks:home']);
        $response->assertJsonPath('total', 1);
    }

    #[Test]
    public function svg_endpoint_returns_svg_document(): void
    {
        $response = $this->get($this->url('/b10cks/home.svg?token=iconify-token'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');
        $this->assertStringContainsString('<svg', $response->getContent());
        $this->assertStringContainsString('<path d="M1 1"/>', $response->getContent());
    }

    #[Test]
    public function mismatched_space_path_is_rejected(): void
    {
        $this->getJson("/api/v1/iconify/some-other-space/b10cks.json?token=iconify-token")
            ->assertNotFound();
    }

    #[Test]
    public function request_without_token_is_unauthorized(): void
    {
        $this->getJson($this->url('/b10cks.json'))->assertUnauthorized();
    }
}
