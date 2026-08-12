<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AuditLogFilter;
use App\Http\Resources\Management\AuditLogResource;
use App\Models\Management\Space;
use App\Models\Space\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AuditLogController extends Controller
{
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorize('viewAny', [AuditLog::class, $space]);

        $perPage = $this->perPage($request, 20, 100);

        $logs = AuditLog::filter(AuditLogFilter::fromRequest($request))
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $ownerIds = $logs->getCollection()
            ->where('owner_type', 'user')
            ->pluck('owner_id')
            ->filter()
            ->unique();

        AuditLogResource::withOwners(
            $ownerIds->isEmpty()
                ? collect()
                : User::whereIn('id', $ownerIds)->get()->keyBy('id')
        );

        return AuditLogResource::collection($logs);
    }
}
