<?php

namespace App\Listeners\Ai;

use App\Models\Space\Content;
use App\Services\Ai\Translation\TranslationMemory;

/**
 * Runs after a publish commits: a translation's fields go into the translation
 * memory. It may not fail the committed publish.
 */
class LearnFromPublishedContent
{
    public function __construct(private readonly TranslationMemory $memory) {}

    public function handle(Content $content): void
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
    }
}
