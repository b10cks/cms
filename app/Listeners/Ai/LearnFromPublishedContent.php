<?php

namespace App\Listeners\Ai;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Ai\Learning\EditLearning;
use App\Services\Ai\Translation\TranslationMemory;

/**
 * Runs after a publish commits: a translation's fields go into the
 * translation memory, and AI-written fields an editor changed before
 * publishing feed the learning loop. Neither may fail the committed publish.
 */
class LearnFromPublishedContent
{
    public function __construct(
        private readonly TranslationMemory $memory,
        private readonly EditLearning $learning,
    ) {}

    public function handle(Content $content, Space $space, ?User $owner): void
    {
        if ($content->published_version?->id !== $content->published_version_id) {
            $content->load('published_version');
        }

        try {
            if ($content->i18n_parent_id !== null) {
                $this->memory->record($content);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $this->learning->fromPublish($space, $content, $owner);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
