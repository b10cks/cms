<?php

namespace App\Events\Space;

use App\Events\Concerns\ResolvesBroadcastPayload;
use App\Http\Resources\Management\ContentMenuResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class ContentUpdated implements ShouldBroadcast
{
    use InteractsWithSockets;
    use ResolvesBroadcastPayload;
    use SerializesModels;

    public Space $space;

    /**
     * Resolved eagerly: space models can't be restored on a queue worker
     * where no space connection is bound.
     *
     * Menu-shaped on purpose: the full content resource (block schema, editor
     * config, resolved content, versions) routinely exceeds Pusher's 10KB
     * message limit, and the only consumer is the content menu tree.
     *
     * @var array<string, mixed>
     */
    public array $payload;

    public function __construct(Content $content, Space $space)
    {
        $this->space = $space;

        $content->load(['block:id,type,icon,color', 'i18n_children']);
        $content->loadCount(['children']);

        $this->payload = $this->resolveBroadcastPayload(ContentMenuResource::make($content)) + [
            'i18n_parent_id' => $content->i18n_parent_id,
            'sv' => $this->resolveSortValue($content),
        ];
    }

    /**
     * The content-field sort value the menu keeps alongside the item, resolved
     * only when the parent actually sorts its children by a content field.
     */
    protected function resolveSortValue(Content $content): string|int|float|null
    {
        if (! $content->parent_id) {
            return null;
        }

        $field = self::childSortFieldFor($this->space->id, $content->parent_id);

        if ($field === null) {
            return null;
        }

        $value = $content->current_version?->content[$field] ?? null;

        return \is_string($value) || \is_int($value) || \is_float($value) ? $value : null;
    }

    /**
     * Memoized per parent and request: bulk operations (move, publish, import)
     * fire ContentUpdated once per sibling, and the parent's sort field cannot
     * change mid-request. Static on purpose — once() keys its cache on the
     * calling object, and a fresh event instance per save would never hit.
     * The space id is part of the key: the query runs on the contextual space
     * connection, so the same parent id may resolve differently per space.
     */
    protected static function childSortFieldFor(string $spaceId, string $parentId): ?string
    {
        // $spaceId is captured solely to scope the memo key.
        return once(function () use ($spaceId, $parentId) {
            return Content::query()
                ->whereKey($parentId)
                ->first(['id', 'settings'])
                ?->settings
                ?->getChildContentSortField();
        });
    }

    public function broadcastOn()
    {
        return [
            new PrivateChannel('spaces.'.$this->space->id.'.content'),
        ];
    }

    public function broadcastAs()
    {
        return 'content:updated';
    }

    public function broadcastWith()
    {
        return $this->payload;
    }
}
