<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\NotificationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The authenticated user's in-app notification inbox.
 *
 * Every query is scoped through the user's own `notifications` relation, so a
 * user can never read or mutate another user's notifications.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = $user->notifications()
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->paginate($request->integer('per_page', 20));

        return NotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $notification): NotificationResource
    {
        /** @var User $user */
        $user = $request->user();

        $record = $user->notifications()->findOrFail($notification);
        $record->markAsRead();

        return new NotificationResource($record);
    }

    public function markAsUnread(Request $request, string $notification): NotificationResource
    {
        /** @var User $user */
        $user = $request->user();

        $record = $user->notifications()->findOrFail($notification);
        $record->markAsUnread();

        return new NotificationResource($record);
    }

    public function markAllAsRead(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function destroy(Request $request, string $notification): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->notifications()->findOrFail($notification)->delete();

        return response()->noContent();
    }

    public function destroyAll(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->notifications()->delete();

        return response()->noContent();
    }

    public function destroyAllRead(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->notifications()->whereNotNull('read_at')->delete();

        return response()->noContent();
    }
}
