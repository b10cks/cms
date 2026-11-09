<?php

namespace Tests\Feature\Ai;

use App\Enums\AiKnowledgeType;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class AiStyleGuideLimitTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->url = "/mgmt/v1/spaces/{$this->space->id}/ai/knowledge";
        Sanctum::actingAs($this->user);
    }

    #[Test]
    public function saving_a_style_guide_over_the_limit_is_refused_with_its_length(): void
    {
        $long = str_repeat('a', AiKnowledgeDocument::MAX_STYLE_CHARS + 1);

        $this->postJson($this->url, ['title' => 'Dutch', 'slug' => 'dutch', 'type' => 'style', 'locale' => 'nl', 'draft' => $long])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['draft' => 'A style guide can hold at most 20,000 characters. This draft has 20,001.']);
        $this->assertSame(0, AiKnowledgeDocument::count());

        $this->postJson($this->url, ['title' => 'Specs', 'slug' => 'specs', 'type' => 'reference', 'draft' => $long])->assertCreated();
        $id = $this->postJson($this->url, ['title' => 'Dutch', 'slug' => 'dutch', 'type' => 'style', 'draft' => str_repeat('ä', AiKnowledgeDocument::MAX_STYLE_CHARS)])
            ->assertCreated()->json('data.id');

        $this->patchJson("{$this->url}/{$id}", ['draft' => $long])->assertJsonValidationErrors('draft');
        $specs = AiKnowledgeDocument::where('slug', 'specs')->value('id');
        $this->patchJson("{$this->url}/{$specs}", ['type' => 'style'])->assertJsonValidationErrors('draft');
    }

    #[Test]
    public function an_older_guide_over_the_limit_takes_other_edits_but_cannot_publish_a_new_revision(): void
    {
        $document = AiKnowledgeDocument::create([
            ...AiKnowledgeDocument::ownerAttributes($this->space),
            'slug' => 'dutch', 'title' => 'Dutch', 'type' => AiKnowledgeType::Style, 'enabled' => true,
            'draft' => str_repeat('a', AiKnowledgeDocument::MAX_STYLE_CHARS + 500),
        ]);

        $this->patchJson("{$this->url}/{$document->id}", ['title' => 'Nederlands', 'draft' => $document->draft])->assertOk();

        $this->postJson("{$this->url}/{$document->id}/publish")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['draft' => 'A style guide can hold at most 20,000 characters. This draft has 20,500.']);
        $this->assertNull($document->refresh()->published_revision_id);
        $this->assertSame(0, $document->revisions()->count());
    }
}
