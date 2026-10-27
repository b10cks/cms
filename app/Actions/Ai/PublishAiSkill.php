<?php

namespace App\Actions\Ai;

use App\Models\Management\AiSkill;
use App\Models\Management\AiSkillRevision;
use App\Models\User;
use App\Services\Ai\Skills\SkillParser;
use Illuminate\Support\Facades\DB;

/** Freezes a skill's draft into an immutable revision that runs will use. */
class PublishAiSkill
{
    public function __construct(private readonly SkillParser $parser) {}

    public function handle(AiSkill $skill, User $user): AiSkillRevision
    {
        $files = $skill->draft['files'] ?? [];
        $this->parser->parse($files);
        ksort($files);
        $hash = hash('sha256', json_encode($files, JSON_UNESCAPED_UNICODE) ?: '');

        return DB::transaction(function () use ($skill, $user, $files, $hash) {
            $current = $skill->publishedRevision;
            if ($current && $current->hash === $hash) {
                return $current;
            }

            $revision = $skill->revisions()->create([
                'revision' => (int) $skill->revisions()->max('revision') + 1,
                'manifest' => ['files' => $files],
                'hash' => $hash,
                'published_by' => $user->id,
            ]);

            $skill->forceFill(['published_revision_id' => $revision->id, 'updated_by' => $user->id])->save();

            return $revision;
        });
    }
}
