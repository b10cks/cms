<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ManagementServer;
use App\Mcp\Support\OperationRegistry;
use App\Mcp\Tools\ContentModelGuideTool;
use App\Mcp\Tools\MgmtCallTool;
use App\Mcp\Tools\MgmtOperationsTool;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(ManagementServer::class)]
#[CoversClass(MgmtCallTool::class)]
#[CoversClass(MgmtOperationsTool::class)]
#[CoversClass(ContentModelGuideTool::class)]
#[CoversClass(OperationRegistry::class)]
class ManagementServerTest extends TestCase
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
    }

    #[Test]
    public function it_lists_all_operations()
    {
        ManagementServer::tool(MgmtOperationsTool::class)
            ->assertOk()
            ->assertSee('blocks.sync')
            ->assertSee('contents.list')
            ->assertSee('spaces.usageTimeseries');
    }

    #[Test]
    public function it_returns_the_content_model_guide()
    {
        ManagementServer::tool(ContentModelGuideTool::class)
            ->assertOk()
            ->assertSee('b10cks Content Modeling Guide');
    }

    #[Test]
    public function it_serves_the_content_model_guide_resource()
    {
        ManagementServer::resource(\App\Mcp\Resources\ContentModelGuideResource::class)
            ->assertOk()
            ->assertSee('b10cks Content Modeling Guide');
    }

    #[Test]
    public function it_rejects_unknown_operations()
    {
        ManagementServer::tool(MgmtCallTool::class, ['operation' => 'nope.nope'])
            ->assertHasErrors()
            ->assertSee('Did you mean');
    }

    #[Test]
    public function it_suggests_close_matches_for_misspelled_operations()
    {
        ManagementServer::tool(MgmtCallTool::class, ['operation' => 'content.list'])
            ->assertHasErrors()
            ->assertSee('contents.list');
    }

    #[Test]
    public function it_reports_missing_required_arguments()
    {
        ManagementServer::tool(MgmtCallTool::class, ['operation' => 'blocks.list'])
            ->assertHasErrors(['Missing required string argument: spaceId']);
    }

    #[Test]
    public function it_executes_a_list_operation_against_the_management_api()
    {
        Block::factory()->create(['slug' => 'heroBanner', 'name' => 'Hero Banner']);

        ManagementServer::tool(MgmtCallTool::class, [
            'operation' => 'blocks.list',
            'spaceId' => $this->space->id,
        ])
            ->assertOk()
            ->assertSee('heroBanner');
    }

    #[Test]
    public function it_executes_a_create_operation_with_a_payload()
    {
        ManagementServer::tool(MgmtCallTool::class, [
            'operation' => 'blocks.create',
            'spaceId' => $this->space->id,
            'payload' => [
                'name' => 'Teaser',
                'slug' => 'teaser',
                'type' => 'nestable',
            ],
        ])
            ->assertOk()
            ->assertSee('teaser');

        $this->assertDatabaseHas(Block::class, ['slug' => 'teaser']);
    }

    #[Test]
    public function it_surfaces_api_validation_errors()
    {
        ManagementServer::tool(MgmtCallTool::class, [
            'operation' => 'blocks.create',
            'spaceId' => $this->space->id,
            'payload' => ['slug' => 'missingName'],
        ])
            ->assertHasErrors()
            ->assertSee('Management API request failed');
    }

    #[Test]
    public function it_resolves_specific_id_arguments_with_generic_id_fallback()
    {
        $block = Block::factory()->create(['slug' => 'cardGrid', 'name' => 'Card Grid']);

        ManagementServer::tool(MgmtCallTool::class, [
            'operation' => 'blocks.get',
            'spaceId' => $this->space->id,
            'id' => $block->id,
        ])
            ->assertOk()
            ->assertSee('cardGrid');
    }

    #[Test]
    public function every_operation_uri_only_uses_known_placeholders()
    {
        $known = [
            'spaceId', 'teamId', 'userId', 'token', 'provider', 'accessToken',
            'id', 'folderId', 'tagId', 'contentId', 'blockId', 'assetId', 'redirectId',
            'tokenId', 'dataSourceId', 'entryId', 'versionId', 'automationId', 'actionId',
            'executionId', 'releaseId', 'commentId', 'templateId', 'configId', 'backupId',
            'migrationId', 'inviteId', 'noteId', 'iconId', 'collectionId', 'shareId',
            'packageId', 'notificationId', 'periodId', 'roleId', 'blueprintId',
            'fieldPluginId',
        ];

        foreach (OperationRegistry::all() as $name => $operation) {
            preg_match_all('/\{(\w+)}/', $operation['uri'], $matches);

            foreach ($matches[1] as $placeholder) {
                $this->assertContains($placeholder, $known, "Operation [{$name}] uses unknown placeholder [{$placeholder}]");
            }

            $this->assertContains($operation['method'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], "Operation [{$name}] has invalid method");
            $this->assertStringStartsWith('/mgmt/v1/', $operation['uri'], "Operation [{$name}] has non-mgmt URI");
        }
    }

    #[Test]
    public function every_operation_uri_matches_a_registered_route()
    {
        $router = $this->app['router'];
        $routes = collect($router->getRoutes()->getRoutes())
            ->map(fn ($route) => array_map(
                fn (string $method) => $method.' '.preg_replace('/\{[^}]+}/', '{}', $route->uri()),
                array_diff($route->methods(), ['HEAD', 'OPTIONS'])
            ))
            ->flatten()
            ->flip();

        foreach (OperationRegistry::all() as $name => $operation) {
            $normalized = $operation['method'].' '.ltrim((string) preg_replace('/\{[^}]+}/', '{}', $operation['uri']), '/');

            $this->assertTrue(
                $routes->has($normalized),
                "Operation [{$name}] does not match any registered route: {$normalized}"
            );
        }
    }
}
