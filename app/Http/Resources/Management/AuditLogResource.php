<?php

namespace App\Http\Resources\Management;

use App\Models\Space\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditSubjectRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = $this->meta;
        $routeName = AuditSubjectRegistry::getRouteName($this->referenced_type);
        $routeParams = $routeName
            ? AuditSubjectRegistry::getRouteParams($this->referenced_type, $this->referenced_id, $meta)
            : null;

        return [
            'id' => $this->getRouteKey(),
            'created_at' => $this->created_at?->toIso8601String(),
            'referenced_type' => $this->referenced_type,
            'referenced_id' => $this->referenced_id,
            'name' => $this->name,
            'operation' => $this->operation,
            'key' => $this->referenced_type.'.'.$this->operation,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'owner_name' => $this->owner_name,
            'owner' => $this->resolveOwnerResource(),
            'item' => [
                'exists' => $routeName !== null && $routeParams !== null,
                'route_name' => $routeName,
                'route_params' => $routeParams ?? [],
                'route_query' => null,
            ],
        ];
    }

    private function resolveOwnerResource(): ?SimpleUserResource
    {
        if ($this->owner_type !== 'user' || ! $this->owner_id) {
            return null;
        }

        $user = User::find($this->owner_id);

        return $user ? new SimpleUserResource($user) : null;
    }
}
