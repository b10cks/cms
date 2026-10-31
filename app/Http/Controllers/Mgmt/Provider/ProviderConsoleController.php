<?php

namespace App\Http\Controllers\Mgmt\Provider;

use App\Enums\InstanceAuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\Provider\InstanceAuditResource;
use App\Models\System\AuditLog;
use App\Services\Provider\InstanceHealth;
use App\Services\Provider\TenantDirectory;
use App\Services\Provider\UsageRollup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Read-only provider console pages. Root-only through the `root` middleware. */
class ProviderConsoleController extends Controller
{
    public function tenants(TenantDirectory $tenants): JsonResponse
    {
        return response()->json(['data' => $tenants->all()]);
    }

    public function usage(Request $request, UsageRollup $usage): JsonResponse
    {
        $validated = $request->validate(['month' => ['sometimes', 'date_format:Y-m']]);
        $month = isset($validated['month'])
            ? Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth()
            : now()->startOfMonth();

        return response()->json([
            'data' => $usage->forMonth($month),
            'meta' => ['month' => $month->format('Y-m')],
        ]);
    }

    public function health(Request $request, InstanceHealth $health): JsonResponse
    {
        return response()->json(['data' => $health->report($request->boolean('recheck'))]);
    }

    public function audit(Request $request): ResourceCollection
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'string', Rule::in(array_column(InstanceAuditAction::cases(), 'value'))],
        ]);

        $entries = AuditLog::query()
            ->with('user:id,firstname,lastname,email')
            ->when(
                isset($validated['action']),
                fn ($query) => $query->where('action', $validated['action']),
                fn ($query) => $query->where('action', 'like', InstanceAuditAction::PREFIX.'%'),
            )
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 50, 100));

        return InstanceAuditResource::collection($entries);
    }
}
