<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\Space\FieldPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class FieldPluginSandboxTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected FieldPlugin $plugin;

    protected string $code = 'window.b10cksFieldPlugin={mount(el,api){el.textContent="hi</scr"+"ipt>"}}';

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');

        $this->setUpSpaceTesting($this->space);

        $this->plugin = FieldPlugin::withoutEvents(
            fn () => FieldPlugin::factory()->published($this->code)->create(['handle' => 'sandboxed'])
        );
    }

    protected function sandboxUrl(?string $version = null): string
    {
        return URL::signedRoute('mgmt.spaces.field-plugins.sandbox', [
            'space' => $this->space->id,
            'fieldPlugin' => $this->plugin->id,
            'v' => $version ?? $this->plugin->code_hash,
        ]);
    }

    #[Test]
    public function unsigned_request_is_rejected(): void
    {
        $url = route('mgmt.spaces.field-plugins.sandbox', [
            'space' => $this->space->id,
            'fieldPlugin' => $this->plugin->id,
        ]);

        $this->get($url)->assertStatus(403);
    }

    #[Test]
    public function signed_request_serves_the_shell_with_embedded_bundle(): void
    {
        $response = $this->get($this->sandboxUrl());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();
        $this->assertStringContainsString('b10cks-plugin', $html);
        // Bundle is embedded JSON-escaped: literal </script> sequences must not appear raw.
        $this->assertStringContainsString('window.b10cksFieldPlugin', $html);
        $this->assertStringNotContainsString('hi</scr', $html);
    }

    #[Test]
    public function security_headers_are_set(): void
    {
        $response = $this->get($this->sandboxUrl());

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString("form-action 'none'", $csp);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    #[Test]
    public function matching_version_is_immutable_cacheable(): void
    {
        $response = $this->get($this->sandboxUrl());

        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function stale_version_is_not_cached(): void
    {
        $response = $this->get($this->sandboxUrl(hash('sha256', 'old-version')));

        $response->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function unpublished_plugin_is_not_served(): void
    {
        $draft = FieldPlugin::withoutEvents(fn () => FieldPlugin::factory()->create(['handle' => 'draft-plugin']));

        $url = URL::signedRoute('mgmt.spaces.field-plugins.sandbox', [
            'space' => $this->space->id,
            'fieldPlugin' => $draft->id,
        ]);

        $this->get($url)->assertStatus(404);
    }

    #[Test]
    public function inactive_plugin_is_not_served(): void
    {
        FieldPlugin::withoutEvents(function () {
            $this->plugin->is_active = false;
            $this->plugin->save();

            return null;
        });

        $this->get($this->sandboxUrl())->assertStatus(404);
    }
}
