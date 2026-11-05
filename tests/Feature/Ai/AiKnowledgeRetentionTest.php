<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PublishAiKnowledgeDocument;
use App\Enums\AiKnowledgeType;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\AiKnowledgeRevision;
use App\Models\Management\Space;
use App\Models\Space\AiRun;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Knowledge\KnowledgeSearch;
use App\Services\Ai\Run\RunToolFactory;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Tools\ReadKnowledgeTool;
use App\Services\Ai\Tools\SearchKnowledgeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiKnowledgeRetentionTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => ['default_language' => 'en']]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->fakeAi();
        Sanctum::actingAs($this->user);
    }

    #[Test]
    public function a_pinned_run_keeps_reference_text_and_names_after_deletion_rename_and_replacement(): void
    {
        $document = $this->publish('warranty', 'Warranty', 'Warranty lasts ten years on every frame.');
        $revisionId = $document->published_revision_id;
        $run = $this->startRun();
        [$search, $read] = $this->tools($run);

        // Rename and a new revision.
        $document->update(['title' => 'Renamed', 'slug' => 'renamed', 'draft' => 'Warranty lasts two years now.']);
        app(PublishAiKnowledgeDocument::class)->handle($document->refresh(), $this->user);
        $hit = $search->execute(['query' => 'warranty'])['results'][0];
        $this->assertSame(['Warranty', 'warranty', $revisionId], [$hit['title'], $hit['slug'], $hit['revision_id']]);
        $this->assertStringContainsString('ten years', $hit['excerpt']);

        // Deleted through the API, then a new document takes the old slug.
        $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/ai/knowledge/{$document->id}")->assertNoContent();
        $this->publish('warranty', 'Replacement', 'Warranty lasts one year.');

        $result = $search->execute(['query' => 'warranty']);
        $this->assertArrayNotHasKey('unavailable', $result);
        $this->assertCount(1, $result['results']);
        $this->assertStringContainsString('ten years', $result['results'][0]['excerpt']);
        $this->assertSame($revisionId, $search->takeSources()[0]['revision_id']);

        $read = $read->execute(['slug' => 'warranty']);
        $this->assertSame('Warranty', $read['title']);
        $this->assertStringContainsString('ten years', $read['text']);
        $this->assertStringContainsString('ten years', $this->tools($run)[1]->execute(['chunk_id' => $hit['chunk_id']])['text']);

        // New work sees the live state, not the retained document.
        $this->assertSame(['Replacement'], array_column(app(KnowledgeResolver::class)->livePolicy($this->space)->references(null), 'title'));
    }

    #[Test]
    public function a_revision_lost_before_retention_is_reported_and_never_an_empty_success(): void
    {
        $document = $this->publish('warranty', 'Warranty', 'Warranty lasts ten years.');
        $run = $this->startRun();
        [$search, $read] = $this->tools($run);

        // What a deletion did before this migration, or removal of the owning team.
        $document->forceDelete();
        $this->assertSame(0, AiKnowledgeRevision::query()->count());

        $result = $search->execute(['query' => 'warranty']);
        $this->assertSame([], $result['results']);
        $this->assertSame('Warranty', $result['unavailable'][0]['title']);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('no longer exists');
        $read->execute(['slug' => 'warranty']);
    }

    #[Test]
    public function a_run_without_a_pinned_policy_reads_its_own_revisions_and_is_named_by_the_row(): void
    {
        $document = $this->publish('warranty', 'Warranty', 'Warranty lasts ten years.');
        $revisions = [$document->published_revision_id => $document->id];
        $search = new SearchKnowledgeTool(app(KnowledgeSearch::class), $revisions);
        $read = new ReadKnowledgeTool($revisions);

        $document->update(['title' => 'Renamed', 'slug' => 'renamed', 'draft' => 'Live wording only.']);
        app(PublishAiKnowledgeDocument::class)->handle($document->refresh(), $this->user);
        $document->delete();

        $hit = $search->execute(['query' => 'warranty'])['results'][0];
        $this->assertSame('Renamed', $hit['title']);
        $this->assertStringContainsString('ten years', $hit['excerpt']);
        $this->assertStringContainsString('ten years', $read->execute(['slug' => 'renamed'])['text']);
        $this->assertStringNotContainsString('Live wording', $read->execute(['slug' => 'renamed'])['text']);
    }

    /**
     * The tools as RunToolFactory builds them for a run, with the returned hook.
     *
     * @return array{SearchKnowledgeTool, ReadKnowledgeTool}
     */
    private function tools(AiRun $run): array
    {
        $run->setup = [...$run->setup, 'tools' => ['search_knowledge', 'read_knowledge']];
        $tools = app(RunToolFactory::class)->make($run, $this->space, $this->user);

        return [$tools->get('search_knowledge'), $tools->get('read_knowledge')];
    }

    private function startRun(): AiRun
    {
        return app(AiRunService::class)->start(new StartRun($this->space, $this->user, 'bulk:content', prompt: 'Noop'));
    }

    private function publish(string $slug, string $title, string $text): AiKnowledgeDocument
    {
        $document = AiKnowledgeDocument::create([...AiKnowledgeDocument::ownerAttributes($this->space), 'slug' => $slug, 'title' => $title, 'type' => AiKnowledgeType::Reference, 'enabled' => true, 'draft' => $text]);
        app(PublishAiKnowledgeDocument::class)->handle($document, $this->user);

        return $document->refresh();
    }
}
