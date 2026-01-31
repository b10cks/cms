<?php

namespace Database\Factories\Space;

use App\Models\Space\Comment;
use App\Models\Space\Content;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        return [
            'content_id' => Content::factory(),
            'author_id' => User::factory(),
            'parent_id' => null,
            'body' => $this->faker->paragraph(),
            'is_resolved' => $this->faker->boolean(20), // 20% chance of being resolved
            'item_id' => null,
            'field' => null,
            'position' => null,
            'mentions_ids' => [],
        ];
    }

    /**
     * Create a root comment (no parent)
     */
    public function root(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'parent_id' => null,
            ];
        });
    }

    /**
     * Create a reply to a specific comment
     */
    public function replyTo(Comment $parentComment): static
    {
        return $this->state(function (array $attributes) use ($parentComment) {
            return [
                'parent_id' => $parentComment->id,
                'content_id' => $parentComment->content_id,
            ];
        });
    }

    /**
     * Create a resolved comment
     */
    public function resolved(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_resolved' => true,
                'resolved_at' => now(),
            ];
        });
    }

    /**
     * Create an unresolved comment
     */
    public function unresolved(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_resolved' => false,
                'resolved_at' => null,
            ];
        });
    }

    /**
     * Set a specific author
     */
    public function byAuthor(User $author): static
    {
        return $this->state(function (array $attributes) use ($author) {
            return [
                'author_id' => $author->id,
            ];
        });
    }

    /**
     * Create a comment with position metadata
     */
    public function withPosition(int $x, int $y): static
    {
        return $this->state(function (array $attributes) use ($x, $y) {
            return [
                'position' => [
                    'x' => $x,
                    'y' => $y,
                ],
            ];
        });
    }

    /**
     * Create a comment with item metadata
     */
    public function forItem(string $itemId, string $field = 'general'): static
    {
        return $this->state(function (array $attributes) use ($itemId, $field) {
            return [
                'item_id' => $itemId,
                'field' => $field,
            ];
        });
    }
}
