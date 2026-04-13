<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\System\AuditLog;
use App\Models\User;
use App\Services\System\AuditService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentPublishingVersioningTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $auditService = Mockery::mock(AuditService::class);
        $auditService->shouldReceive('log')->andReturn(new AuditLog);
        app()->instance(AuditService::class, $auditService);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'summary' => [
                    'type' => 'text',
                    'name' => 'Summary',
                    'required' => true,
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['summary'],
            ]],
        ]);
    }

    #[Test]
    public function update_without_content_keeps_the_existing_draft_version(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );
        $originalCurrentVersionId = $content->current_version_id;

        $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'name' => 'Updated name',
        ])->assertOk();

        $content->refresh()->load('current_version');

        $this->assertSame(2, $content->versions()->count());
        $this->assertSame($originalCurrentVersionId, $content->current_version_id);
        $this->assertSame(['summary' => 'Draft summary'], $content->current_version->content);
    }

    #[Test]
    public function publish_without_content_reuses_the_current_draft_version(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );
        $draftVersionId = $content->current_version_id;

        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [])->assertOk();

        $content->refresh()->load(['current_version', 'published_version']);

        $this->assertSame(2, $content->versions()->count());
        $this->assertSame($draftVersionId, $content->current_version_id);
        $this->assertSame($draftVersionId, $content->published_version_id);
        $this->assertSame(['summary' => 'Draft summary'], $content->published_version->content);
        $this->assertNotNull($content->current_version->published_at);
    }

    #[Test]
    public function publish_with_identical_content_does_not_create_another_version(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
        );
        $publishedVersionId = $content->current_version_id;

        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'content' => ['summary' => 'Published summary'],
        ])->assertOk();

        $content->refresh();

        $this->assertSame(1, $content->versions()->count());
        $this->assertSame($publishedVersionId, $content->current_version_id);
        $this->assertSame($publishedVersionId, $content->published_version_id);
    }

    private function createVersionedContent(array $publishedContent, ?array $currentContent = null): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->pageBlock->id,
            'name' => 'Page',
            'slug' => strtolower((string) Str::random(8)),
            'full_slug' => '/' . strtolower((string) Str::random(8)),
            'language_iso' => 'en',
        ]);
        $content->id = strtolower((string) Str::ulid());

        $publishedVersion = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => $publishedContent,
            'created_by_id' => $this->owner->id,
            'published_by_id' => $this->owner->id,
            'published_at' => Carbon::parse('2026-01-01 10:00:00'),
        ]);

        $currentVersion = $publishedVersion;

        if ($currentContent !== null) {
            $currentVersion = ContentVersion::query()->forceCreate([
                'content_id' => $content->id,
                'parent_id' => $publishedVersion->id,
                'content' => $currentContent,
                'created_by_id' => $this->owner->id,
            ]);
        }

        $content->current_version_id = $currentVersion->id;
        $content->published_version_id = $publishedVersion->id;
        $content->published_at = $publishedVersion->published_at;
        $content->first_published_at = $publishedVersion->published_at;
        $content->save();

        return $content->fresh();
    }
}
