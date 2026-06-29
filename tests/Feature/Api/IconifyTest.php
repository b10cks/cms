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
            'space_id'   => $this->space->id,
            'token'      => 'iconify-token',
            'expires_at' => null,
        ]);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        Icon::factory()->create(['key' => 'home', 'name' => 'Home', 'body' => '<path d="M1 1"/>', 'width' => 24, 'height' => 24]);
        Icon::factory()->create(['key' => 'arrow', 'name' => 'Arrow', 'body' => '<path d="M2 2"/>', 'width' => 16, 'height' => 16]);
    }

    private function url(string $suffix): string
    {
        return '/api/v1/iconify' . $suffix;
    }

    // -------------------------------------------------------------------------
    // JSON data endpoint
    // -------------------------------------------------------------------------

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
    public function icon_data_returns_404_for_unknown_prefix(): void
    {
        $this->getJson($this->url('/unknown.json?token=iconify-token'))->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Metadata endpoints
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // SVG endpoint
    // -------------------------------------------------------------------------

    #[Test]
    public function svg_endpoint_returns_svg_document(): void
    {
        $response = $this->get($this->url('/b10cks/home.svg?token=iconify-token'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');
        $content = $response->getContent();
        $this->assertStringContainsString('<svg', $content);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $content);
        $this->assertStringContainsString('<path d="M1 1"/>', $content);
    }

    #[Test]
    public function svg_endpoint_defaults_to_1em_dimensions(): void
    {
        $content = $this->get($this->url('/b10cks/home.svg?token=iconify-token'))->getContent();

        $this->assertStringContainsString('width="1em"', $content);
        $this->assertStringContainsString('height="1em"', $content);
    }

    #[Test]
    public function svg_endpoint_accepts_custom_dimensions(): void
    {
        $content = $this->get($this->url('/b10cks/home.svg?token=iconify-token&width=48&height=48'))->getContent();

        $this->assertStringContainsString('width="48"', $content);
        $this->assertStringContainsString('height="48"', $content);
    }

    #[Test]
    public function svg_endpoint_sets_color_attribute_for_currentcolor_theming(): void
    {
        $content = $this->get($this->url('/b10cks/home.svg?token=iconify-token&color=red'))->getContent();

        $this->assertStringContainsString('color="red"', $content);
    }

    #[Test]
    public function svg_endpoint_applies_horizontal_flip(): void
    {
        $content = $this->get($this->url('/b10cks/home.svg?token=iconify-token&flip=horizontal'))->getContent();

        $this->assertStringContainsString('<g transform="translate(24 0) scale(-1 1)">', $content);
    }

    #[Test]
    public function svg_endpoint_applies_vertical_flip(): void
    {
        $content = $this->get($this->url('/b10cks/home.svg?token=iconify-token&flip=vertical'))->getContent();

        $this->assertStringContainsString('<g transform="translate(0 24) scale(1 -1)">', $content);
    }

    #[Test]
    public function svg_endpoint_applies_90_degree_rotation(): void
    {
        $content = $this->get($this->url('/b10cks/home.svg?token=iconify-token&rotate=1'))->getContent();

        $this->assertStringContainsString('<g transform="rotate(90 12 12)">', $content);
    }

    #[Test]
    public function svg_endpoint_accepts_deg_suffix_for_rotation(): void
    {
        $a = $this->get($this->url('/b10cks/home.svg?token=iconify-token&rotate=1'))->getContent();
        $b = $this->get($this->url('/b10cks/home.svg?token=iconify-token&rotate=90deg'))->getContent();

        $this->assertSame($a, $b);
    }

    #[Test]
    public function svg_endpoint_combines_rotation_and_flip_in_single_g(): void
    {
        // Matches actual Iconify API output: rotate(90 12 12) translate(24 0) scale(-1 1)
        $content = $this->get($this->url('/b10cks/home.svg?token=iconify-token&rotate=1&flip=horizontal'))->getContent();

        $this->assertStringContainsString('<g transform="rotate(90 12 12) translate(24 0) scale(-1 1)">', $content);
    }

    #[Test]
    public function svg_endpoint_treats_both_flips_as_180_rotation(): void
    {
        $both   = $this->get($this->url('/b10cks/home.svg?token=iconify-token&flip=horizontal,vertical'))->getContent();
        $rotate = $this->get($this->url('/b10cks/home.svg?token=iconify-token&rotate=2'))->getContent();

        $this->assertSame($both, $rotate);
    }

    #[Test]
    public function svg_endpoint_adds_bounding_box_when_box_param_is_set(): void
    {
        $content = $this->get($this->url('/b10cks/home.svg?token=iconify-token&box=1'))->getContent();

        $this->assertStringContainsString('<rect width="24" height="24" fill="none"/>', $content);
    }

    #[Test]
    public function svg_endpoint_returns_404_for_unknown_icon(): void
    {
        $this->get($this->url('/b10cks/no-such-icon.svg?token=iconify-token'))->assertNotFound();
    }

    #[Test]
    public function svg_endpoint_returns_404_for_unknown_prefix(): void
    {
        $this->get($this->url('/unknown/home.svg?token=iconify-token'))->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // CSS endpoint — multiple icons
    // -------------------------------------------------------------------------

    #[Test]
    public function css_endpoint_requires_icons_param(): void
    {
        $this->get($this->url('/b10cks.css?token=iconify-token'))->assertStatus(400);
    }

    #[Test]
    public function css_endpoint_returns_css_content_type(): void
    {
        $response = $this->get($this->url('/b10cks.css?token=iconify-token&icons=home'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/css; charset=utf-8');
    }

    #[Test]
    public function css_endpoint_generates_mask_mode_for_currentcolor_icons(): void
    {
        Icon::factory()->create([
            'key'  => 'stroke-icon',
            'body' => '<path fill="none" stroke="currentColor" d="M0 0"/>',
            'width' => 24, 'height' => 24,
        ]);

        $css = $this->get($this->url('/b10cks.css?token=iconify-token&icons=stroke-icon'))->getContent();

        $this->assertStringContainsString('mask-image: var(--svg)', $css);
        $this->assertStringContainsString('background-color: currentColor', $css);
        $this->assertStringNotContainsString('background-image', $css);
    }

    #[Test]
    public function css_endpoint_generates_background_mode_for_colorful_icons(): void
    {
        Icon::factory()->create([
            'key'  => 'colorful-icon',
            'body' => '<circle fill="red" cx="12" cy="12" r="10"/>',
            'width' => 24, 'height' => 24,
        ]);

        $css = $this->get($this->url('/b10cks.css?token=iconify-token&icons=colorful-icon'))->getContent();

        $this->assertStringContainsString('background-image', $css);
        $this->assertStringNotContainsString('mask-image', $css);
    }

    #[Test]
    public function css_endpoint_replaces_currentcolor_with_black_in_data_url(): void
    {
        Icon::factory()->create([
            'key'  => 'themed',
            'body' => '<path stroke="currentColor" d="M0 0"/>',
            'width' => 24, 'height' => 24,
        ]);

        $css = $this->get($this->url('/b10cks.css?token=iconify-token&icons=themed'))->getContent();

        // currentColor must be replaced with black inside the embedded SVG data URL
        $this->assertStringContainsString('stroke=\'black\'', $css);
        // The data URL itself must not contain currentColor (background-color: currentColor in the rule is fine)
        preg_match('/url\("([^"]+)"\)/', $css, $m);
        $this->assertNotEmpty($m[1], 'Expected a data URL in CSS');
        $this->assertStringNotContainsString('currentColor', urldecode($m[1]));
    }

    #[Test]
    public function css_endpoint_respects_color_param_in_data_url(): void
    {
        Icon::factory()->create([
            'key'  => 'themed2',
            'body' => '<path stroke="currentColor" d="M0 0"/>',
            'width' => 24, 'height' => 24,
        ]);

        $css = $this->get($this->url('/b10cks.css?token=iconify-token&icons=themed2&color=red'))->getContent();

        $this->assertStringContainsString('stroke=\'red\'', $css);
    }

    #[Test]
    public function css_endpoint_outputs_correct_selectors(): void
    {
        $css = $this->get($this->url('/b10cks.css?token=iconify-token&icons=home'))->getContent();

        $this->assertStringContainsString('.icon--b10cks {', $css);
        $this->assertStringContainsString('.icon--b10cks--home {', $css);
    }

    #[Test]
    public function css_endpoint_accepts_custom_selector_and_common(): void
    {
        $css = $this->get($this->url('/b10cks.css?token=iconify-token&icons=home&selector=.my-{name}&common=.my-icon'))->getContent();

        $this->assertStringContainsString('.my-icon {', $css);
        $this->assertStringContainsString('.my-home {', $css);
    }

    #[Test]
    public function css_endpoint_accepts_custom_css_variable_name(): void
    {
        $css = $this->get($this->url('/b10cks.css?token=iconify-token&icons=home&var=icon'))->getContent();

        $this->assertStringContainsString('--icon:', $css);
        $this->assertStringContainsString('var(--icon)', $css);
        $this->assertStringNotContainsString('--svg', $css);
    }

    #[Test]
    public function css_endpoint_can_force_mask_mode(): void
    {
        // colorful-icon would normally use background mode, but mode=mask forces it
        Icon::factory()->create([
            'key'  => 'colorful2',
            'body' => '<circle fill="red" cx="12" cy="12" r="10"/>',
            'width' => 24, 'height' => 24,
        ]);

        $css = $this->get($this->url('/b10cks.css?token=iconify-token&icons=colorful2&mode=mask'))->getContent();

        $this->assertStringContainsString('mask-image', $css);
    }

    #[Test]
    public function css_endpoint_returns_404_for_unknown_prefix(): void
    {
        $this->get($this->url('/unknown.css?token=iconify-token&icons=home'))->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // CSS endpoint — single icon
    // -------------------------------------------------------------------------

    #[Test]
    public function css_single_endpoint_returns_css_for_one_icon(): void
    {
        $css = $this->get($this->url('/b10cks/home.css?token=iconify-token'))->getContent();

        $this->assertStringContainsString('.icon--b10cks {', $css);
        $this->assertStringContainsString('.icon--b10cks--home {', $css);
        $this->assertStringContainsString('--svg:', $css);
    }

    #[Test]
    public function css_single_endpoint_returns_404_for_unknown_icon(): void
    {
        $this->get($this->url('/b10cks/no-such.css?token=iconify-token'))->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    #[Test]
    public function request_without_token_is_unauthorized(): void
    {
        $this->getJson($this->url('/b10cks.json'))->assertUnauthorized();
    }
}
