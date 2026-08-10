<?php

namespace Tests\Unit\Models\Space;

use App\Models\Space\Content;
use App\Services\Automation\Enums\TriggerType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rule behind the `content_published` / `content_unpublished` automations.
 *
 * It is exercised here rather than through a publish request because the
 * dispatch itself is deferred to `afterCommit`, which never runs inside the
 * transaction the database test traits wrap every test in — a request-level
 * test would pass whatever the rule said.
 */
class ContentPublicationTriggerTest extends TestCase
{
    #[Test]
    public function the_first_publish_fires_published(): void
    {
        $content = $this->savedContent(
            before: ['published_at' => null, 'published_version_id' => null],
            after: ['published_at' => '2026-01-01 10:00:00', 'published_version_id' => 'v1'],
        );

        $this->assertSame(TriggerType::CONTENT_PUBLISHED, $content->publicationTrigger());
    }

    /**
     * The regression this rule exists for: `published_at` stays set across an
     * edit, so a republish is no longer a null transition.
     */
    #[Test]
    public function republishing_a_live_entry_fires_published(): void
    {
        $content = $this->savedContent(
            before: ['published_at' => '2026-01-01 10:00:00', 'published_version_id' => 'v1'],
            after: ['published_at' => '2026-02-01 10:00:00', 'published_version_id' => 'v2'],
        );

        $this->assertSame(TriggerType::CONTENT_PUBLISHED, $content->publicationTrigger());
    }

    #[Test]
    public function unpublishing_fires_unpublished(): void
    {
        $content = $this->savedContent(
            before: ['published_at' => '2026-01-01 10:00:00', 'published_version_id' => 'v1'],
            after: ['published_at' => null, 'published_version_id' => 'v1'],
        );

        $this->assertSame(TriggerType::CONTENT_UNPUBLISHED, $content->publicationTrigger());
    }

    /**
     * A draft save stages a new current version and leaves the published
     * pointer alone. It used to clear `published_at`, which fired a spurious
     * `content_unpublished` on every edit of a live entry.
     */
    #[Test]
    public function a_draft_save_fires_nothing(): void
    {
        $content = $this->savedContent(
            before: ['published_at' => '2026-01-01 10:00:00', 'published_version_id' => 'v1', 'current_version_id' => 'v1'],
            after: ['published_at' => '2026-01-01 10:00:00', 'published_version_id' => 'v1', 'current_version_id' => 'v2'],
        );

        $this->assertNull($content->publicationTrigger());
    }

    #[Test]
    public function a_rename_of_an_offline_entry_fires_nothing(): void
    {
        $content = $this->savedContent(
            before: ['published_at' => null, 'published_version_id' => 'v1', 'name' => 'Old'],
            after: ['published_at' => null, 'published_version_id' => 'v1', 'name' => 'New'],
        );

        $this->assertNull($content->publicationTrigger());
    }

    /**
     * Publishing an entry whose current version is already the published one —
     * a publish click with nothing staged — moves no pointer and is not a
     * republish.
     */
    #[Test]
    public function a_publish_that_changes_no_version_fires_nothing(): void
    {
        $content = $this->savedContent(
            before: ['published_at' => '2026-01-01 10:00:00', 'published_version_id' => 'v1'],
            after: ['published_at' => '2026-02-01 10:00:00', 'published_version_id' => 'v1'],
        );

        $this->assertNull($content->publicationTrigger());
    }

    /**
     * Builds a Content in the state it would be in immediately after `save()`:
     * originals as they were loaded, `changes` recorded for the write.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function savedContent(array $before, array $after): Content
    {
        $content = new Content;
        $content->setRawAttributes($before, sync: true);

        foreach ($after as $key => $value) {
            $content->setAttribute($key, $value);
        }

        $content->syncChanges();

        return $content;
    }
}
