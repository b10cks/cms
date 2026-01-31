<?php

namespace App\Http\Controllers\Mgmt;

use App\Events\Space\ContentPresenceUpdated;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Http\Request;

class PresenceController
{
    public function updateSpacePresence(Request $request, Space $space): array
    {
        $request->validate([
            'action' => 'required|in:join,leave',
        ]);

        return ['status' => 'success'];
    }

    public function updateContentPresence(
        Request $request,
        Space $space,
        ?Content $content = null
    ): array {
        $request->validate([
            'previous_content_id' => 'nullable|string',
            'action' => 'required|in:join,leave',
        ]);

        $user = $request->user();
        $previousContentId = $request->input('previous_content_id');
        $action = $request->input('action');

        if ($previousContentId && $action === 'join') {
            broadcast(new ContentPresenceUpdated(
                $space->id,
                $previousContentId,
                [
                    'id' => $user->id,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'avatar' => $user->avatar_url,
                ],
                'leave'
            ))->toOthers();
        }

        if ($content && $action === 'join') {
            broadcast(new ContentPresenceUpdated(
                $space->id,
                $content->id,
                [
                    'id' => $user->id,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'avatar' => $user->avatar_url,
                ],
                'join'
            ))->toOthers();
        }

        return ['status' => 'success'];
    }

    public function leaveSpacePresence(Request $request, Space $space): array
    {
        return ['status' => 'success'];
    }

    public function leaveContentPresence(
        Request $request,
        Space $space,
        Content $content
    ): array {
        $user = $request->user();

        broadcast(new ContentPresenceUpdated(
            $space->id,
            $content->id,
            [
                'id' => $user->id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'avatar' => $user->avatar_url,
            ],
            'leave'
        ))->toOthers();

        return ['status' => 'success'];
    }
}
