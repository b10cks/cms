<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\TeamAuditLogResource;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\System\AuditLog;
use App\Models\User;
use App\Services\Team\TeamScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The team's activity: changes to the team and its sub-teams, and membership
 * changes in their spaces. Entries come from the management `audit_logs`
 * table that AuditService and the Auditable trait write.
 */
class TeamAuditLogController extends Controller
{
    /** Space actions that belong to the team's activity; content stays in the space's own log. */
    private const SPACE_ACTIONS = [
        'member_added',
        'member_removed',
        'member_role_changed',
        'member_expiry_changed',
    ];

    public function index(Request $request, Team $team): ResourceCollection
    {
        $this->authorize('viewAuditLogs', $team);

        $scope = TeamScope::for($team);

        $logs = AuditLog::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('entity_type', Team::class)
                    ->whereIn('entity_id', $scope->teamIds))
                ->orWhere(fn (Builder $query) => $query
                    ->where('entity_type', Space::class)
                    ->whereIn('entity_id', $scope->spaceIds)
                    ->whereIn('action', self::SPACE_ACTIONS)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 20, 100));

        $entries = $logs->getCollection();

        $teamIds = $entries->where('entity_type', Team::class)->pluck('entity_id')
            ->merge($entries->flatMap(fn (AuditLog $log) => [
                $log->old_values['parent_id'] ?? null,
                $log->new_values['parent_id'] ?? null,
            ]))
            ->filter()
            ->unique();
        $spaceIds = $entries->where('entity_type', Space::class)->pluck('entity_id')->unique();
        $userIds = $entries->pluck('user_id')
            ->merge($entries->map(fn (AuditLog $log) => $log->metadata['member_id'] ?? null))
            ->filter()
            ->unique();

        TeamAuditLogResource::withLookups(
            teams: Team::withTrashed()->whereIn('id', $teamIds)->pluck('name', 'id'),
            spaces: Space::withTrashed()->whereIn('id', $spaceIds)->pluck('name', 'id'),
            users: User::withTrashed()->whereIn('id', $userIds)->get()->keyBy('id'),
        );

        return TeamAuditLogResource::collection($logs);
    }
}
