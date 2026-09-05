<?php

use App\Models\Management\Space;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use Illuminate\Support\Facades\Broadcast;

$canView = static fn (User $user, Space $space, string $ability): bool => app(AuthorizationService::class)->canInSpace($user, $space, $ability);

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (string) $user->id === (string) $id;
});

Broadcast::channel('spaces.{space}.content', function (User $user, Space $space) use ($canView) {
    return $canView($user, $space, 'content.view');
});

foreach (['blocks', 'assets', 'icons', 'redirects', 'data_sources'] as $resource) {
    Broadcast::channel("spaces.{space}.{$resource}", function (User $user, Space $space) use ($canView, $resource) {
        return $canView($user, $space, "{$resource}.view");
    });
}

Broadcast::channel('presence-spaces.{spaceId}', function (User $user, string $spaceId) use ($canView) {
    $space = Space::find($spaceId);

    if (! $space || ! $canView($user, $space, 'space.view')) {
        return false;
    }

    return [
        'id' => $user->id,
        'firstname' => $user->firstname,
        'lastname' => $user->lastname,
        'email' => $user->email,
        'avatar' => $user->avatar_url,
        'joined_at' => now()->toIso8601String(),
    ];
});

Broadcast::channel('presence-spaces.{space}.content', function (User $user, Space $space) use ($canView) {
    if (! $canView($user, $space, 'content.view')) {
        return false;
    }

    return [
        'id' => $user->id,
        'firstname' => $user->firstname,
        'lastname' => $user->lastname,
        'email' => $user->email,
        'avatar' => $user->avatar_url,
        'joined_at' => now()->toIso8601String(),
    ];
});

Broadcast::channel('presence-spaces.{space}.content.{contentId}', function (User $user, Space $space, string $contentId) use ($canView) {
    if (! $canView($user, $space, 'content.view')) {
        return false;
    }

    return [
        'id' => $user->id,
        'firstname' => $user->firstname,
        'lastname' => $user->lastname,
        'email' => $user->email,
        'avatar' => $user->avatar_url,
        'joined_at' => now()->toIso8601String(),
    ];
});

Broadcast::channel('presence-spaces.{space}.content-canvas', function (User $user, Space $space) use ($canView) {
    if (! $canView($user, $space, 'content.view')) {
        return false;
    }

    return [
        'id' => $user->id,
        'firstname' => $user->firstname,
        'lastname' => $user->lastname,
        'email' => $user->email,
        'avatar' => $user->avatar_url,
        'joined_at' => now()->toIso8601String(),
    ];
});
