<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\RedirectController;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(RedirectController::class)]
class RedirectTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();

        $this->assignSpaceRole($this->space, $this->user, 'owner');
        Sanctum::actingAs($this->user);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    #[Test]
    public function user_can_create_a_redirect(): void
    {
        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/redirects", [
            'source' => '/old-path',
            'target' => '/new-path',
            'status_code' => 301,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.source', '/old-path');
        $response->assertJsonPath('data.target', '/new-path');
        $response->assertJsonPath('data.status_code', 301);

        $this->assertDatabaseHas('redirects', [
            'source' => '/old-path',
            'target' => '/new-path',
            'status_code' => 301,
        ]);
    }

    #[Test]
    public function user_can_update_a_redirect(): void
    {
        $redirect = Redirect::query()->create([
            'source' => '/legacy-path',
            'target' => '/archive',
            'status_code' => 302,
        ]);

        $response = $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/redirects/{$redirect->id}", [
            'source' => '/legacy-path',
            'target' => '/latest',
            'status_code' => 308,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.target', '/latest');
        $response->assertJsonPath('data.status_code', 308);

        $redirect->refresh();
        $this->assertSame('/latest', $redirect->target);
        $this->assertSame(308, $redirect->status_code);
    }

    #[Test]
    public function redirect_import_creates_and_updates_records(): void
    {
        $existingRedirect = Redirect::query()->create([
            'source' => '/existing',
            'target' => '/old-target',
            'status_code' => 301,
        ]);

        $importFile = UploadedFile::fake()->createWithContent(
            'redirect-import.json',
            json_encode([
                'redirects' => [
                    [
                        'id' => $existingRedirect->id,
                        'source' => '/existing',
                        'target' => '/updated-target',
                        'status_code' => 308,
                        'ignored_column' => 'ignore me',
                    ],
                    [
                        'source' => '/brand-new',
                        'target' => '/fresh-target',
                        'status_code' => 302,
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/redirects/import",
            ['file' => $importFile],
            ['Accept' => 'application/json']
        );

        $response->assertOk();
        $response->assertJsonPath('summary.total_success', 2);
        $response->assertJsonPath('summary.total_errors', 0);
        $response->assertJsonPath('ignored_fields.0', 'ignored_column');

        $existingRedirect->refresh();
        $this->assertSame('/updated-target', $existingRedirect->target);
        $this->assertSame(308, $existingRedirect->status_code);

        $this->assertDatabaseHas('redirects', [
            'source' => '/brand-new',
            'target' => '/fresh-target',
            'status_code' => 302,
        ]);
    }

    #[Test]
    public function redirect_export_returns_redirect_payload(): void
    {
        Redirect::query()->create([
            'source' => '/from-a',
            'target' => '/to-a',
            'status_code' => 301,
        ]);

        Redirect::query()->create([
            'source' => '/from-b',
            'target' => '/to-b',
            'status_code' => 302,
        ]);

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/redirects/export", [
            'as' => 'json',
        ]);

        $response->assertOk();

        $payload = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($this->space->id, $payload['space_id']);
        $this->assertCount(2, $payload['redirects']);
        $this->assertSame('/from-a', $payload['redirects'][0]['source']);
        $this->assertSame('/to-b', $payload['redirects'][1]['target']);
    }
}
