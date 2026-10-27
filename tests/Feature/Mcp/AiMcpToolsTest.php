<?php

namespace Tests\Feature\Mcp;

use App\Actions\Ai\PublishAiKnowledgeDocument;
use App\Actions\Ai\PublishAiSkill;
use App\Enums\AiKnowledgeType;
use App\Mcp\Servers\ManagementServer;
use App\Mcp\Tools\ListSkillsTool;
use App\Mcp\Tools\LookupGlossaryTool;
use App\Mcp\Tools\MgmtCallTool;
use App\Mcp\Tools\ReadSkillTool;
use App\Mcp\Tools\SearchContentTool;
use App\Mcp\Tools\SearchKnowledgeTool;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryTerm;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\AiSkill;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Ai\Skills\SkillResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function lookup_glossary_returns_the_required_wording_for_the_target_locale(): void
    {
        $glossary = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Brand']);
        AiGlossaryTerm::create([
            'glossary_id' => $glossary->id, 'source_locale' => 'en', 'term' => 'Dashboard',
            'translations' => ['de' => 'Übersicht'],
        ]);

        ManagementServer::actingAs($this->user)->tool(LookupGlossaryTool::class, [
            'spaceId' => $this->space->id, 'text' => 'Open the Dashboard.', 'source_locale' => 'en', 'target_locale' => 'de',
        ])->assertOk()->assertSee('Übersicht');
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
    public function a_base64_glossary_upload_imports_its_terms_and_an_oversize_one_is_rejected(): void
    {
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $glossary = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Shop', 'enabled' => true]);
        $args = ['operation' => 'ai.glossaries.import', 'spaceId' => $this->space->id, 'id' => $glossary->id];
        $csv = "source_locale,term,case_sensitive,do_not_translate,forbidden,note\nen,Checkout,0,0,cart,\nen,b10cks,1,1,,Brand\n";

        ManagementServer::actingAs($this->user)->tool(MgmtCallTool::class, [
            ...$args,
            'payload' => ['file' => ['filename' => 'terms.csv', 'content_base64' => base64_encode($csv)]],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(['Checkout', 'b10cks'], $glossary->terms()->pluck('term')->all());

        ManagementServer::actingAs($this->user)->tool(MgmtCallTool::class, [
            ...$args,
            'payload' => ['file' => ['filename' => 'big.csv', 'content_base64' => str_repeat('A', 14_000_000)]],
        ])->assertHasErrors(['exceeds the upload limit of 10240 KB']);
    }

}
