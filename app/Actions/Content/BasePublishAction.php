<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;

abstract class BasePublishAction
{
    /**
     * Content writes retry on deadlock. Each attempt re-adopts the locked row,
     * but anything the closure broadcasts or queues directly still goes out
     * once per attempt.
     */
    public const TRANSACTION_ATTEMPTS = 3;

    abstract public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): void;

    protected function extractDataFromRequest(array &$data): array
    {
        $contentData = data_get($data, 'content');
        $message = data_get($data, 'message');
        $publishedAt = $this->resolvePublishedAt($data);

        unset($data['content']);
        unset($data['message']);
        unset($data['published_at']);
        unset($data['scheduled_at']);

        return compact('contentData', 'message', 'publishedAt');
    }

    /**
     * Saves only when the request changed a column: a clean save still fires
     * the saved hook, which broadcasts the pre-publish state. Callers have
     * already loaded current_version through lockContentForUpdate().
     */
    protected function updateContent(array $data, Content $content): void
    {
        $content->fill($data);

        if ($content->isDirty()) {
            $content->save();
        }
    }

    protected function resolveRequestedContent(array $data, Content $content): array
    {
        if (! array_key_exists('content', $data)) {
            return $content->getCurrentContent();
        }

        return is_array($data['content'] ?? null) ? $data['content'] : [];
    }

    protected function buildBaseValues(?string $message, Authenticatable|User|null $owner): array
    {
        return [
            'message' => $message,
            'published_by_id' => $owner?->id,
        ];
    }

    protected function resolvePublishedAt(array $data): Carbon
    {
        $publishedAt = data_get($data, 'published_at');

        return $publishedAt ? Carbon::parse((string) $publishedAt) : now();
    }

    protected function shouldReuseCurrentVersion(array $contentData, Content $content): bool
    {
        $content->loadMissing('current_version');

        return $content->current_version?->content == $contentData;
    }

    protected function currentVersionIsDraft(Content $content): bool
    {
        $content->loadMissing('current_version');

        return $content->current_version?->published_at === null;
    }

    protected function updateExistingVersion(array $values, Content $content): void
    {
        $updated = ContentVersion::where('id', '=', $content->current_version_id)
            ->where('content_id', $content->id)
            ->whereNull('published_at')
            ->update($values);

        if ($updated === 0) {
            throw new \Exception(
                'Cannot update version: it has already been published or does not exist.'
            );
        }
    }

    protected function syncCurrentVersionPublication(array $values, Content $content): void
    {
        $updated = ContentVersion::where('id', '=', $content->current_version_id)
            ->where('content_id', $content->id)
            ->update($values);

        if ($updated === 0) {
            throw new \Exception(
                'Cannot update version: it does not exist.'
            );
        }
    }

    protected function createNewVersion(
        array $values,
        array $contentData,
        Content $content,
        Authenticatable|User|null $owner
    ): ContentVersion {
        return ContentVersion::createWithContentContext($values + [
            'content_id' => $content->id,
            'parent_id' => $content->current_version_id,
            'content' => $contentData,
            'created_by_id' => $owner?->id,
        ], $content);
    }

    protected function loadPublishedVersion(Content $content): void
    {
        $content->load('published_version');
    }

    /**
     * Lock the content row and adopt its committed state, so the version this
     * action branches from is the one committed right now, not the one the
     * route model resolved before a concurrent update. Adopting the whole row
     * also resets what a rolled-back transaction attempt left on the model, so
     * a deadlock retry starts clean. Call inside the transaction, a lock taken
     * outside is released immediately.
     */
    protected function lockContentForUpdate(Content $content): void
    {
        $locked = Content::query()->lockForUpdate()->findOrFail($content->id);
        $content->setRawAttributes($locked->getAttributes(), true);
        $content->load('current_version');
    }

    /**
     * Reads first and updates by primary key. A locking UPDATE on these
     * conditions picks the (scheduled_at, published_at) index and gap-locks its
     * tail, which deadlocks two concurrent publishes in one space. The content
     * row lock from lockContentForUpdate() already serializes writers of this
     * content, so the unlocked read is safe.
     */
    protected function clearScheduledVersions(Content $content, ?ContentVersion $exceptVersion = null): void
    {
        $ids = ContentVersion::query()
            ->where('content_id', $content->id)
            ->whereNotNull('scheduled_at')
            ->whereNull('published_at')
            ->when($exceptVersion, fn ($query) => $query->where('id', '!=', $exceptVersion->id))
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            ContentVersion::query()->whereKey($ids)->update(['scheduled_at' => null]);
        }
    }

    protected function touchSpace(Space $space): void
    {
        try {
            $space->touchContentUpdatedAt();
        } catch (\Exception $e) {
            \Log::error('Failed to touch space', [
                'space_id' => $space->id,
                'column' => 'content_updated_at',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
