<?php

namespace Database\Factories\Space;

use App\Models\Space\Comment;
use App\Models\Space\CommentReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommentReactionFactory extends Factory
{
    protected $model = CommentReaction::class;

    public function definition(): array
    {
        // Emoji codes using colon format like :+1:, :heart:, etc.
        $emojiCodes = [
            ':+1:',
            ':-1:',
            ':heart:',
            ':joy:',
            ':open_mouth:',
            ':cry:',
            ':rage:',
            ':tada:',
            ':sparkles:',
            ':rocket:',
            ':eyes:',
            ':fire:',
            ':100:',
            ':white_check_mark:',
            ':x:',
        ];

        return [
            'comment_id' => Comment::factory(),
            'author_id' => User::factory(),
            'emoji' => $this->faker->randomElement($emojiCodes),
        ];
    }

    /**
     * Create a reaction for a specific comment
     */
    public function forComment(Comment $comment): static
    {
        return $this->state(function (array $attributes) use ($comment) {
            return [
                'comment_id' => $comment->id,
            ];
        });
    }

    /**
     * Create a reaction by a specific user
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
     * Create a reaction with a specific emoji code
     */
    public function withEmoji(string $emoji): static
    {
        return $this->state(function (array $attributes) use ($emoji) {
            return [
                'emoji' => $emoji,
            ];
        });
    }

    /**
     * Create a thumbs up reaction
     */
    public function thumbsUp(): static
    {
        return $this->withEmoji(':+1:');
    }

    /**
     * Create a heart reaction
     */
    public function heart(): static
    {
        return $this->withEmoji(':heart:');
    }

    /**
     * Create a laughing reaction
     */
    public function laughing(): static
    {
        return $this->withEmoji(':joy:');
    }

    /**
     * Create a celebration reaction
     */
    public function celebrate(): static
    {
        return $this->withEmoji(':tada:');
    }

    /**
     * Create a rocket reaction
     */
    public function rocket(): static
    {
        return $this->withEmoji(':rocket:');
    }

    /**
     * Create a fire reaction
     */
    public function fire(): static
    {
        return $this->withEmoji(':fire:');
    }

    /**
     * Create an eyes reaction
     */
    public function eyes(): static
    {
        return $this->withEmoji(':eyes:');
    }
}
