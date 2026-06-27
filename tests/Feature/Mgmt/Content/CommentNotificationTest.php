<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Comment;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Notifications\Space\CommentReplyNotification;
use App\Notifications\Space\MentionNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class CommentNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $author;

    protected User $member;

    protected Space $space;

    protected Content $content;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create();
        $this->member = User::factory()->create();

        $this->space = Space::factory()->create();
        $this->setUpSpaceTesting($this->space);

        $block = Block::forceCreate([
            'external_id' => fake()->uuid(),
            'name' => 'Test Block',
            'slug' => 'test-block',
            'type' => 'text',
        ]);

        // contents.current_version_id and content_versions.content_id are both
        // NOT NULL, so pre-assign the content id to break the cycle.
        $contentId = (string) Str::ulid();
        $version = ContentVersion::forceCreate(['content_id' => $contentId]);

        $this->content = new Content;
        $this->content->id = $contentId;
        $this->content->forceFill([
            'block_id' => $block->id,
            'name' => 'Test Content',
            'slug' => 'test-content',
            'full_slug' => 'test-content',
            'language_iso' => 'en',
            'current_version_id' => $version->id,
        ])->save();
    }

    private function createComment(array $attributes): Comment
    {
        return Comment::create(array_merge(['content_id' => $this->content->id], $attributes));
    }

    #[Test]
    public function it_notifies_a_mentioned_user(): void
    {
        Notification::fake();

        $this->createComment([
            'author_id' => $this->author->id,
            'body' => "Hey @{$this->member->id}, take a look",
        ]);

        Notification::assertSentTo($this->member, MentionNotification::class);
    }

    #[Test]
    public function it_does_not_notify_the_author_for_their_own_mention(): void
    {
        Notification::fake();

        $this->createComment([
            'author_id' => $this->author->id,
            'body' => "Note to self @{$this->author->id}",
        ]);

        Notification::assertNotSentTo($this->author, MentionNotification::class);
    }

    #[Test]
    public function it_notifies_the_parent_author_on_a_reply(): void
    {
        $root = $this->createComment([
            'author_id' => $this->member->id,
            'body' => 'Original comment',
        ]);

        Notification::fake();

        $this->createComment([
            'author_id' => $this->author->id,
            'parent_id' => $root->id,
            'body' => 'Thanks for the comment',
        ]);

        Notification::assertSentTo($this->member, CommentReplyNotification::class);
    }
}
