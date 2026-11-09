<?php

namespace Tests\Feature\Mcp;

use App\Actions\Ai\PublishAiKnowledgeDocument;
use App\Actions\Ai\PublishAiSkill;
use App\Enums\AiKnowledgeType;
use App\Mcp\Servers\ManagementServer;
use App\Mcp\Tools\ListSkillsTool;
use App\Mcp\Tools\MgmtCallTool;
use App\Mcp\Tools\ReadSkillTool;
use App\Mcp\Tools\SearchContentTool;
use App\Mcp\Tools\SearchKnowledgeTool;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\AiSkill;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Ai\Skills\SkillResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class AiMcpToolsTest extends TestCase
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
        $this->assignSpaceRole($this->space, $this->user, 'editor');
        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function an_mcp_token_sees_the_skills_and_knowledge_of_its_user(): void
    {
        $skill = AiSkill::create([
            ...AiSkill::ownerAttributes($this->space),
            'name' => 'sparkle',
            'draft' => ['files' => ['SKILL.md' => "---\nname: sparkle\ndescription: Adds sparkle.\n---\n\nMake it sparkle."]],
        ]);
        app(PublishAiSkill::class)->handle($skill, $this->user);

        $document = AiKnowledgeDocument::create([
            ...AiKnowledgeDocument::ownerAttributes($this->space),
            'slug' => 'shipping', 'title' => 'Shipping', 'type' => AiKnowledgeType::Reference,
            'draft' => "# Shipping\n\nParcels leave the warehouse on Tuesdays.",
        ]);
        app(PublishAiKnowledgeDocument::class)->handle($document, $this->user);

        $expected = array_keys(app(SkillResolver::class)->resolve($this->space));
        $args = ['spaceId' => $this->space->id];

        $response = ManagementServer::actingAs($this->user)->tool(ListSkillsTool::class, $args)->assertOk();
        foreach ($expected as $name) {
            $response->assertSee($name);
        }

        ManagementServer::actingAs($this->user)->tool(ReadSkillTool::class, [...$args, 'name' => 'sparkle'])
            ->assertOk()->assertSee('Make it sparkle.');
        ManagementServer::actingAs($this->user)->tool(SearchKnowledgeTool::class, [...$args, 'query' => 'warehouse parcels'])
            ->assertOk()->assertSee('Tuesdays');

        $outsider = User::factory()->create();
        ManagementServer::actingAs($outsider)->tool(ListSkillsTool::class, $args)->assertHasErrors();
        ManagementServer::actingAs($outsider)->tool(SearchKnowledgeTool::class, [...$args, 'query' => 'warehouse'])->assertHasErrors();
    }

    #[Test]
    public function search_content_only_serves_actors_who_can_read_entries(): void
    {
        Content::factory()->create([
            'name' => 'Launch notes', 'slug' => 'launch', 'full_slug' => '/launch',
            'language_iso' => $this->space->settings->getDefaultLanguage(),
            'published_at' => now(), 'searchable_content' => 'Launch notes for the comet release',
        ]);

        $args = ['spaceId' => $this->space->id, 'query' => 'comet'];

        ManagementServer::actingAs($this->user)->tool(SearchContentTool::class, $args)
            ->assertOk()->assertSee('Launch notes');

        $billing = User::factory()->create();
        $this->assignSpaceRole($this->space, $billing, 'billing');
        ManagementServer::actingAs($billing)->tool(SearchContentTool::class, $args)
            ->assertDontSee('Launch notes');
    }

    #[Test]
    public function a_base64_upload_reaches_the_file_endpoint_and_an_oversize_one_is_rejected(): void
    {
        Storage::fake('transfers');
        Queue::fake();
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $document = AiKnowledgeDocument::create([...AiKnowledgeDocument::ownerAttributes($this->space), 'slug' => 'notes', 'title' => 'Notes', 'description' => 'Notes.', 'type' => AiKnowledgeType::Reference, 'draft' => '']);
        $args = ['operation' => 'ai.knowledge.upload', 'spaceId' => $this->space->id, 'id' => $document->id];

        ManagementServer::actingAs($this->user)->tool(MgmtCallTool::class, [
            ...$args,
            'payload' => ['file' => ['filename' => 'notes.txt', 'content_base64' => base64_encode('Ten years of warranty.')]],
        ])->assertOk();

        $this->assertSame('notes.txt', $document->refresh()->source_name);

        ManagementServer::actingAs($this->user)->tool(MgmtCallTool::class, [
            ...$args,
            'payload' => ['file' => ['filename' => 'big.txt', 'content_base64' => str_repeat('A', 28_000_000)]],
        ])->assertHasErrors(['exceeds the upload limit of 20480 KB']);
    }
}
