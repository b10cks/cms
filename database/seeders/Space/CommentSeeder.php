<?php

namespace Database\Seeders\Space;

use App\Models\Management\Space;
use App\Models\Space\Comment;
use App\Models\Space\CommentReaction;
use App\Models\Space\Content;
use App\Models\User;
use Illuminate\Database\Seeder;

class CommentSeeder extends Seeder
{
    /**
     * Seed the database with comment data for testing.
     */
    public function run(): void
    {
        // Get existing space and content, or create them
        $space = Space::firstOrCreate(
            ['name' => 'Default Space'],
            ['slug' => 'default-space']
        );

        // Create some users for comments
        $users = User::factory(5)->create();

        // Get existing content or create one
        $content = Content::whereHas('space', fn ($q) => $q->where('id', $space->id))->first();

        if (!$content) {
            $this->command->info('Skipping comment seeding - no content found in space');
            return;
        }

        // Create root comments
        for ($i = 0; $i < 3; $i++) {
            $rootComment = Comment::create([
                'content_id' => $content->id,
                'author_id' => $users->random()->id,
                'body' => 'This is root comment number ' . ($i + 1) . '. This comment discusses an important aspect of the content.',
                'parent_id' => null,
                'is_resolved' => false,
            ]);

            // Add reactions to root comment using emoji codes
            foreach ([':+1:', ':heart:', ':joy:', ':tada:'] as $emojiCode) {
                CommentReaction::factory()
                    ->forComment($rootComment)
                    ->byAuthor($users->random())
                    ->withEmoji($emojiCode)
                    ->create();
            }

            // Create 2-3 replies to each root comment
            for ($j = 0; $j < rand(2, 3); $j++) {
                $reply = Comment::create([
                    'content_id' => $content->id,
                    'author_id' => $users->random()->id,
                    'body' => 'This is a reply to root comment number ' . ($i + 1) . ', reply number ' . ($j + 1) . '. It provides additional context.',
                    'parent_id' => $rootComment->id,
                    'is_resolved' => false,
                ]);

                // Add reactions to reply using emoji codes
                foreach ([':+1:', ':heart:'] as $emojiCode) {
                    CommentReaction::factory()
                        ->forComment($reply)
                        ->byAuthor($users->random())
                        ->withEmoji($emojiCode)
                        ->create();
                }

                // Create 1-2 nested replies (replies to replies)
                for ($k = 0; $k < rand(1, 2); $k++) {
                    $nestedReply = Comment::create([
                        'content_id' => $content->id,
                        'author_id' => $users->random()->id,
                        'body' => 'This is a nested reply to the above reply. It continues the discussion.',
                        'parent_id' => $reply->id,
                        'is_resolved' => false,
                    ]);

                    // Add reactions to nested reply using emoji code
                    CommentReaction::factory()
                        ->forComment($nestedReply)
                        ->byAuthor($users->random())
                        ->withEmoji(':+1:')
                        ->create();
                }
            }
        }

        // Create some resolved comments
        for ($i = 0; $i < 2; $i++) {
            Comment::create([
                'content_id' => $content->id,
                'author_id' => $users->random()->id,
                'body' => "This is a resolved comment. It has been addressed and concluded.",
                'parent_id' => null,
                'is_resolved' => true,
                'resolved_at' => now()->subDays(rand(1, 30)),
            ]);
        }

        $this->command->info('Comment seeding completed successfully');
    }
}
