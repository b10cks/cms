<?php

namespace App\Http\Controllers\Mgmt\Provider;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ProviderStatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_root, 403);

        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->string('start_date'))
            : now()->startOfMonth();
        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->string('end_date'))
            : now()->endOfDay();

        $buildStat = static function (string $modelClass) use ($startDate, $endDate) {
            return [
                'total' => $modelClass::query()->count(),
                'new' => $modelClass::query()
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count(),
            ];
        };

        return response()->json([
            'data' => [
                'range' => [
                    'start_date' => $startDate->toIso8601String(),
                    'end_date' => $endDate->toIso8601String(),
                ],
                'summary' => [
                    'teams' => $buildStat(Team::class),
                    'spaces' => $buildStat(Space::class),
                    'users' => $buildStat(User::class),
                ],
            ],
        ]);
    }
}
