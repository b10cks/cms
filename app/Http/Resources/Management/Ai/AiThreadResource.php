<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Management\AiConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A thread row. Needs {@see AiConversation::scopeWithStatus()} and the owner
 * loaded. The detail view adds `context` and `turns`, or for the suggestions
 * thread `suggestions` and `glossaries`.
 *
 * @mixin AiConversation
 */
class AiThreadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'status' => $this->status()->value,
            'shared' => $this->shared,
            'quiet' => $this->quiet,
            'archived' => $this->archived_at !== null,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'owner' => $this->owner ? [
                'id' => $this->owner->id,
                'name' => trim("{$this->owner->firstname} {$this->owner->lastname}"),
            ] : null,
            'is_owner' => $this->user_id !== null && $this->user_id === $request->user()?->id,
            'entry_count' => (int) $this->getAttribute('entry_count'),
        ];
    }
}
