<?php

namespace Tests\Feature\Mgmt\Content;

use App\Http\Controllers\Mgmt\Content\CommentController;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Comment;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(CommentController::class)]
class CommentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;
    use WithFaker;

    protected User $owner;
    protected User $viewer;
    protected Space $space;
    protected Content $content;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users with different roles
        $this->owner = User::factory()->create();
        $this->viewer = User::factory()->create();

        // Create a space and assign users
        $this->space = Space::factory()->create();
        $this->space->users()->attach($this->owner, ['role' => 'owner']);
        $this->space->users()->attach($this->viewer, ['role' => 'viewer']);

        $this->setUpSpaceTesting($this->space);

        // Create content in the space with minimal dependencies
        $this->content = Content::create([
            'space_id' => $this->space->id,
            'block_id' => Block::create([
                'external_id' => fake()->uuid(),
                'name' => 'Test Block',
                'slug' => 'test-block',
                'type' => 'text',
            ])->id,
            'name' => 'Test Content',
            'slug' => 'test-content',
            'full_slug' => 'test-content',
            'language_iso' => 'en',
            'current_version_id' => ContentVersion::create([
                'content_id' => null, // Will be set later
            ])->id,
        ]);

        // Update the version with the correct content_id
        $this->content->current_version_id = $this->content->versions()->first()->id ?? 
            ContentVersion::create(['content_id' => $this->content->id])->id;
        $this->content->save();
    }

    #[Test]
    public function owner_can_create_root_comment()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(
            route('mgmt.comments.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
            ]),
            [
                'body' => 'This is a root comment',
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.body', 'This is a root comment');
        $response->assertJsonPath('data.parent_id', null);
        $response->assertJsonPath('data.author.id', $this->owner->id);

        $this->assertDatabaseHas('comments', [
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'body' => 'This is a root comment',
            'parent_id' => null,
        ]);
    }

    #[Test]
    public function owner_can_create_reply_to_comment()
    {
        $this->actingAs($this->owner);

        // Create a root comment first
        $rootComment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'parent_id' => null,
        ]);

        // Create a reply to the root comment
        $response = $this->postJson(
            route('mgmt.comments.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
            ]),
            [
                'parent_id' => $rootComment->id,
                'body' => 'This is a reply to the root comment',
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.body', 'This is a reply to the root comment');
        $response->assertJsonPath('data.parent_id', $rootComment->id);
        $response->assertJsonPath('data.author.id', $this->owner->id);

        $this->assertDatabaseHas('comments', [
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'body' => 'This is a reply to the root comment',
            'parent_id' => $rootComment->id,
        ]);
    }

    #[Test]
    public function owner_can_create_nested_reply()
    {
        $this->actingAs($this->owner);

        // Create a root comment
        $rootComment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'parent_id' => null,
        ]);

        // Create a reply to the root comment
        $firstReply = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'parent_id' => $rootComment->id,
        ]);

        // Create a nested reply (reply to a reply)
        $response = $this->postJson(
            route('mgmt.comments.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
            ]),
            [
                'parent_id' => $firstReply->id,
                'body' => 'This is a reply to a reply',
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.parent_id', $firstReply->id);
        $this->assertDatabaseHas('comments', [
            'parent_id' => $firstReply->id,
            'body' => 'This is a reply to a reply',
        ]);
    }

    #[Test]
    public function owner_can_list_root_comments()
    {
        $this->actingAs($this->owner);

        // Create root comments
        $rootComment1 = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'parent_id' => null,
        ]);

        $rootComment2 = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->viewer->id,
            'parent_id' => null,
        ]);

        // Create a reply (should not be in root comments list)
        Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'parent_id' => $rootComment1->id,
        ]);

        $response = $this->getJson(
            route('mgmt.comments.index', [
                'space' => $this->space->id,
                'content' => $this->content->id,
            ])
        );

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $rootComment1->id);
        $response->assertJsonPath('data.1.id', $rootComment2->id);
    }

    #[Test]
    public function root_comments_include_nested_replies()
    {
        $this->actingAs($this->owner);

        // Create a root comment
        $rootComment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'parent_id' => null,
        ]);

        // Create replies
        $reply1 = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'parent_id' => $rootComment->id,
        ]);

        $reply2 = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->viewer->id,
            'parent_id' => $rootComment->id,
        ]);

        $response = $this->getJson(
            route('mgmt.comments.index', [
                'space' => $this->space->id,
                'content' => $this->content->id,
            ])
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $rootComment->id);
        $response->assertJsonPath('data.0.replies_count', 2);
        $response->assertJsonPath('data.0.replies.0.id', $reply1->id);
        $response->assertJsonPath('data.0.replies.1.id', $reply2->id);
    }

    #[Test]
    public function owner_can_view_specific_comment()
    {
        $this->actingAs($this->owner);

        $comment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'body' => 'Test comment body',
        ]);

        $response = $this->getJson(
            route('mgmt.comments.show', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $comment->id,
            ])
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $comment->id);
        $response->assertJsonPath('data.body', 'Test comment body');
        $response->assertJsonPath('data.author.id', $this->owner->id);
    }

    #[Test]
    public function owner_can_update_own_comment()
    {
        $this->actingAs($this->owner);

        $comment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'body' => 'Original body',
        ]);

        $response = $this->patchJson(
            route('mgmt.comments.update', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $comment->id,
            ]),
            [
                'body' => 'Updated body',
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.body', 'Updated body');

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'body' => 'Updated body',
        ]);
    }

    #[Test]
    public function viewer_cannot_update_comment()
    {
        $this->actingAs($this->owner);

        $comment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'body' => 'Original body',
        ]);

        $this->actingAs($this->viewer);

        $response = $this->patchJson(
            route('mgmt.comments.update', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $comment->id,
            ]),
            [
                'body' => 'Updated body',
            ]
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function owner_can_delete_own_comment()
    {
        $this->actingAs($this->owner);

        $comment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
        ]);

        $response = $this->deleteJson(
            route('mgmt.comments.destroy', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $comment->id,
            ])
        );

        $response->assertStatus(204);
        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    #[Test]
    public function viewer_cannot_delete_comment()
    {
        $this->actingAs($this->owner);

        $comment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
        ]);

        $this->actingAs($this->viewer);

        $response = $this->deleteJson(
            route('mgmt.comments.destroy', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $comment->id,
            ])
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function owner_can_resolve_comment()
    {
        $this->actingAs($this->owner);

        $comment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'is_resolved' => false,
        ]);

        $response = $this->postJson(
            route('mgmt.comments.resolve', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $comment->id,
            ])
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_resolved', true);
        $response->assertJsonPath('data.resolved_at', $response->json('data.resolved_at'));

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'is_resolved' => true,
        ]);
    }

    #[Test]
    public function owner_can_unresolve_comment()
    {
        $this->actingAs($this->owner);

        $comment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'is_resolved' => true,
            'resolved_at' => now(),
        ]);

        $response = $this->deleteJson(
            route('mgmt.comments.unresolve', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $comment->id,
            ])
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_resolved', false);
        $response->assertJsonPath('data.resolved_at', null);

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'is_resolved' => false,
            'resolved_at' => null,
        ]);
    }

    #[Test]
    public function viewer_cannot_resolve_comment()
    {
        $this->actingAs($this->owner);

        $comment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'is_resolved' => false,
        ]);

        $this->actingAs($this->viewer);

        $response = $this->postJson(
            route('mgmt.comments.resolve', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $comment->id,
            ])
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function creating_reply_with_invalid_parent_id_fails()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(
            route('mgmt.comments.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
            ]),
            [
                'parent_id' => 'invalid_ulid',
                'body' => 'This is a reply',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.parent_id.0', 'The parent_id field must be a valid ULID.');
    }

    #[Test]
    public function creating_reply_with_nonexistent_parent_fails()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(
            route('mgmt.comments.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
            ]),
            [
                'parent_id' => fake()->ulid(),
                'body' => 'This is a reply',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.parent_id.0', 'The selected parent_id is invalid.');
    }

    #[Test]
    public function creating_comment_without_body_fails()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(
            route('mgmt.comments.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
            ]),
            [
                'body' => '',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.body', ['The body field must have at least 1 character.']);
    }

    #[Test]
    public function creating_comment_with_body_exceeding_max_length_fails()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(
            route('mgmt.comments.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
            ]),
            [
                'body' => str_repeat('a', 10001),
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.body', ['The body field must not be greater than 10000 characters.']);
    }
}
