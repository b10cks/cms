<?php

namespace App\Events\Space;

use App\Events\Concerns\ResolvesBroadcastPayload;
use App\Http\Resources\Management\ContentMenuResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class ContentUpdated implements ShouldBroadcast
{
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

        $field = Content::query()
            ->whereKey($content->parent_id)
            ->first(['id', 'settings'])
            ?->settings
            ?->getChildContentSortField();

        if ($field === null) {
            return null;
        }

        $value = $content->current_version?->content[$field] ?? null;

        return \is_string($value) || \is_int($value) || \is_float($value) ? $value : null;
    }

    public function broadcastOn()
    {
        return [
            new Channel('spaces.'.$this->space->id.'.content'),
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
