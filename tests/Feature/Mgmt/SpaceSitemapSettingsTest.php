<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\System\AuditLog;
use App\Models\User;
use App\Services\System\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class SpaceSitemapSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);

        app()->instance(AuditService::class, new class extends AuditService
        {
            public function log(
                string $action,
                Model $entity,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?array $metadata = null,
                ?User $user = null,
            ): AuditLog {
                return new AuditLog;
            }
        });
    }

    #[Test]
    public function space_settings_accept_valid_sitemap_mappings(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson(route('mgmt.spaces.update', $this->space), [
            'settings' => [
                'sitemap' => [
                    'types' => [
                        ['block' => 'page', 'path' => 'meta'],
                        ['block' => 'article', 'path' => 'seo.meta'],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.settings.sitemap.types.0.block', 'page');
        $response->assertJsonPath('data.settings.sitemap.types.0.path', 'meta');
        $response->assertJsonPath('data.settings.sitemap.types.1.block', 'article');
        $response->assertJsonPath('data.settings.sitemap.types.1.path', 'seo.meta');
    }

    #[Test]
    public function space_settings_reject_duplicate_blocks_and_empty_paths(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson(route('mgmt.spaces.update', $this->space), [
            'settings' => [
                'sitemap' => [
                    'types' => [
                        ['block' => 'page', 'path' => 'meta'],
                        ['block' => 'page', 'path' => ''],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'settings.sitemap.types.1.block',
            'settings.sitemap.types.1.path',
        ]);
    }
}
