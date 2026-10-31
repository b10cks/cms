<?php

namespace App\Http\Resources\Management\Provider;

use App\Models\System\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry of the instance audit stream.
 *
 * @mixin AuditLog
 */
class InstanceAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->user ? [
                'id' => $this->user->id,
                'name' => trim($this->user->name) !== '' ? trim($this->user->name) : $this->user->email,
                'email' => $this->user->email,
            ] : null,
            'entity_type' => class_basename($this->entity_type),
            'entity_id' => $this->entity_id,
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
