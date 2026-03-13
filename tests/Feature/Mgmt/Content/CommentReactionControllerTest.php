<?php

namespace Tests\Feature\Mgmt\Content;

use App\Http\Controllers\Mgmt\Content\CommentReactionController;
use App\Models\Management\Space;
use App\Models\Space\Comment;
use App\Models\Space\CommentReaction;
use App\Models\Space\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(CommentReactionController::class)]
class CommentReactionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;
    use WithFaker;

    protected User $owner;

    protected User $viewer;

    protected Space $space;

    protected Content $content;

    protected Comment $rootComment;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users with different roles
        $this->owner = User::factory()->create();
        $this->viewer = User::factory()->create();

        // Create a space and assign users
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->assignSpaceRole($this->space, $this->viewer, 'viewer');

        $this->setUpSpaceTesting($this->space);

        // Create content in the space
        $this->content = Content::factory()->create([
            'space_id' => $this->space->id,
        ]);

        // Create a root comment for testing
        $this->rootComment = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->owner->id,
            'parent_id' => null,
        ]);
    }

    #[Test]
    public function owner_can_add_reaction_to_root_comment()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(
            route('mgmt.comments.reactions.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
            ]),
            [
                'emoji' => ':+1:',
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.emoji', ':+1:');
        $response->assertJsonPath('data.author.id', $this->owner->id);

        $this->assertDatabaseHas('comment_reactions', [
            'comment_id' => $this->rootComment->id,
            'author_id' => $this->owner->id,
            'emoji' => ':+1:',
        ]);
    }

    #[Test]
    public function owner_can_add_reaction_to_reply()
    {
        $this->actingAs($this->owner);

        // Create a reply
        $reply = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->viewer->id,
            'parent_id' => $this->rootComment->id,
        ]);

        $response = $this->postJson(
            route('mgmt.comments.reactions.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $reply->id,
            ]),
            [
                'emoji' => ':heart:',
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.emoji', ':heart:');
        $response->assertJsonPath('data.author.id', $this->owner->id);

        $this->assertDatabaseHas('comment_reactions', [
            'comment_id' => $reply->id,
            'author_id' => $this->owner->id,
            'emoji' => ':heart:',
        ]);
    }

    #[Test]
    public function owner_can_add_different_emojis_to_same_comment()
    {
        $this->actingAs($this->owner);

        // Add first reaction
        $this->postJson(
            route('mgmt.comments.reactions.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
            ]),
            ['emoji' => ':+1:']
        );

        // Add second reaction
        $this->postJson(
            route('mgmt.comments.reactions.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
            ]),
            ['emoji' => ':heart:']
        );

        $this->assertDatabaseHas('comment_reactions', [
            'comment_id' => $this->rootComment->id,
            'author_id' => $this->owner->id,
            'emoji' => ':+1:',
        ]);

        $this->assertDatabaseHas('comment_reactions', [
            'comment_id' => $this->rootComment->id,
            'author_id' => $this->owner->id,
            'emoji' => ':heart:',
        ]);
    }

    #[Test]
    public function multiple_users_can_add_same_emoji_reaction()
    {
        $this->actingAs($this->owner);

        // Owner adds reaction
        $this->postJson(
            route('mgmt.comments.reactions.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
            ]),
            ['emoji' => ':+1:']
        );

        // Viewer adds same reaction
        $this->actingAs($this->viewer);
        $this->postJson(
            route('mgmt.comments.reactions.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
            ]),
            ['emoji' => ':+1:']
        );

        $this->assertDatabaseHas('comment_reactions', [
            'comment_id' => $this->rootComment->id,
            'author_id' => $this->owner->id,
            'emoji' => ':+1:',
        ]);

        $this->assertDatabaseHas('comment_reactions', [
            'comment_id' => $this->rootComment->id,
            'author_id' => $this->viewer->id,
            'emoji' => ':+1:',
        ]);
    }

    #[Test]
    public function adding_same_reaction_twice_returns_existing_reaction()
    {
        $this->actingAs($this->owner);

        // Add reaction first time
        $response1 = $this->postJson(
            route('mgmt.comments.reactions.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
            ]),
            ['emoji' => ':+1:']
        );

        $reactionId1 = $response1->json('data.id');

        // Add same reaction again
        $response2 = $this->postJson(
            route('mgmt.comments.reactions.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
            ]),
            ['emoji' => ':+1:']
        );

        $reactionId2 = $response2->json('data.id');

        // Should return the same reaction
        $this->assertEquals($reactionId1, $reactionId2);

        // Should only have one reaction in database
        $this->assertCount(1, CommentReaction::where([
            'comment_id' => $this->rootComment->id,
            'author_id' => $this->owner->id,
            'emoji' => ':+1:',
        ])->get());
    }

    #[Test]
    public function owner_can_list_reactions_for_root_comment()
    {
        $this->actingAs($this->owner);

        // Add reactions from multiple users
        CommentReaction::factory()->forComment($this->rootComment)->byAuthor($this->owner)->withEmoji('👍')->create();
        CommentReaction::factory()->forComment($this->rootComment)->byAuthor($this->viewer)->withEmoji('👍')->create();
        CommentReaction::factory()->forComment($this->rootComment)->byAuthor($this->viewer)->withEmoji('❤️')->create();

        $response = $this->getJson(
            route('mgmt.comments.reactions.index', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
            ])
        );

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    #[Test]
    public function owner_can_list_reactions_for_reply()
    {
        $this->actingAs($this->owner);

        // Create a reply
        $reply = Comment::factory()->create([
            'content_id' => $this->content->id,
            'author_id' => $this->viewer->id,
            'parent_id' => $this->rootComment->id,
        ]);

        // Add reactions to the reply
        CommentReaction::factory()->forComment($reply)->byAuthor($this->owner)->withEmoji('👍')->create();
        CommentReaction::factory()->forComment($reply)->byAuthor($this->viewer)->withEmoji('❤️')->create();

        $response = $this->getJson(
            route('mgmt.comments.reactions.index', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $reply->id,
            ])
        );

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    #[Test]
    public function owner_can_delete_own_reaction()
    {
        $this->actingAs($this->owner);

        $reaction = CommentReaction::factory()->create([
            'comment_id' => $this->rootComment->id,
            'author_id' => $this->owner->id,
            'emoji' => ':+1:',
        ]);

        $response = $this->deleteJson(
            route('mgmt.comments.reactions.destroy', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
                'reaction' => $reaction->id,
            ])
        );

        $response->assertStatus(204);
        $this->assertDatabaseMissing('comment_reactions', ['id' => $reaction->id]);
    }

    #[Test]
    public function viewer_cannot_delete_others_reaction()
    {
        $this->actingAs($this->owner);

        $reaction = CommentReaction::factory()->create([
            'comment_id' => $this->rootComment->id,
            'author_id' => $this->owner->id,
            'emoji' => ':+1:',
        ]);

        $this->actingAs($this->viewer);

        $response = $this->deleteJson(
            route('mgmt.comments.reactions.destroy', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
                'reaction' => $reaction->id,
            ])
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function invalid_emoji_fails_validation()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(
            route('mgmt.comments.reactions.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
            ]),
            [
                'emoji' => 'invalid',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.emoji.0', 'The emoji field must be a valid emoji code (e.g., :+1:, :heart:, :eyes:, :+1::skin-tone-4:).');
    }

    #[Test]
    public function empty_emoji_fails_validation()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(
            route('mgmt.comments.reactions.store', [
                'space' => $this->space->id,
                'content' => $this->content->id,
                'comment' => $this->rootComment->id,
            ]),
            [
                'emoji' => '',
            ]
        );

        $response->assertStatus(422);
    }
}
