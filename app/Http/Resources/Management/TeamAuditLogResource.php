<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Team;
use App\Models\System\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin AuditLog
 */
class TeamAuditLogResource extends JsonResource
{
    /** Only these values leave the server; the rest of a team's audited fields (settings) stay out. */
    private const VISIBLE_VALUES = ['role', 'expires_at', 'name', 'parent_id', 'type', 'reason'];

    /** @var Collection<string, string> */
    private static Collection $teams;

    /** @var Collection<string, string> */
    private static Collection $spaces;

    /** @var Collection<string, User> */
    private static Collection $users;

    /**
     * Pre-fetched names, so a page of entries costs three queries.
     *
     * @param  Collection<string, string>  $teams
     * @param  Collection<string, string>  $spaces
     * @param  Collection<string, User>  $users
     */
    public static function withLookups(Collection $teams, Collection $spaces, Collection $users): void
    {
        self::$teams = $teams;
        self::$spaces = $spaces;
        self::$users = $users;
    }

    public function toArray(Request $request): array
    {
        $isTeam = $this->entity_type === Team::class;
        $old = $this->visible($this->old_values);
        $new = $this->visible($this->new_values);

        return [
            'id' => $this->id,
            'action' => $this->action,
            'created_at' => $this->created_at?->toIso8601String(),
            'actor' => $this->user($this->user_id),
            'subject' => [
                'type' => $isTeam ? 'team' : 'space',
                'id' => $this->entity_id,
                'name' => ($isTeam ? self::$teams : self::$spaces)->get($this->entity_id),
            ],
            'member' => $this->user($this->metadata['member_id'] ?? null),
            'old_values' => $old,
            'new_values' => $new,
            'parents' => [
                'from' => isset($old['parent_id']) ? self::$teams->get($old['parent_id']) : null,
                'to' => isset($new['parent_id']) ? self::$teams->get($new['parent_id']) : null,
            ],
            'reason' => $this->metadata['reason'] ?? null,
        ];
    }

    private function visible(?array $values): ?array
    {
        return $values === null ? null : array_intersect_key($values, array_flip(self::VISIBLE_VALUES));
    }

    private function user(?string $id): ?array
    {
        $user = $id ? self::$users->get($id) : null;

        return $user ? [
            'id' => $user->id,
            'name' => $user->display_name,
            'avatar' => $user->avatar_url,
        ] : null;
    }
}
