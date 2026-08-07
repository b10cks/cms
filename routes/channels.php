<?php

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (string) $user->id === (string) $id;
});

Broadcast::channel('spaces.{space}.content', function (User $user, Space $space) {
    if (! $space || ! $user->spaces()->where('spaces.id', $space->id)->exists()) {
        return false;
    }

    return true;
});

foreach (['blocks', 'assets', 'icons', 'redirects', 'data_sources'] as $resource) {
    Broadcast::channel("spaces.{space}.{$resource}", function (User $user, Space $space) {
        return $space && $user->spaces()->where('spaces.id', $space->id)->exists();
    });
}

Broadcast::channel('presence-spaces.{spaceId}', function (User $user, string $spaceId) {
    $space = Space::find($spaceId);

    if (! $space || ! $user->spaces()->where('spaces.id', $space->id)->exists()) {
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

Broadcast::channel('presence-spaces.{space}.content', function (User $user, Space $space) {
    if (! $space || ! $user->spaces()->where('spaces.id', $space->id)->exists()) {
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

Broadcast::channel('presence-spaces.{space}.content.{contentId}', function (User $user, Space $space, string $contentId) {
    if (! $space || ! $user->spaces()->where('spaces.id', $space->id)->exists()) {
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

Broadcast::channel('presence-spaces.{space}.content-canvas', function (User $user, Space $space) {
    if (! $space || ! $user->spaces()->where('spaces.id', $space->id)->exists()) {
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
