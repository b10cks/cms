<?php

namespace App\Services\Asset;

use App\Events\Space\PublicAssetShareTouched;
use App\Models\Space\AssetShare;
use App\Support\SpaceContext;

/**
 * Pings open public share pages (see PublicAssetShareTouched) when a share or
 * its underlying content changes. Every entry point resolves the space from
 * the ambient context and degrades to a no-op without one, so model hooks can
 * call in unconditionally.
 */
class AssetSharePinger
{
    /**
     * Upper bound on pings per trigger. A space with more simultaneously
     * accessible shares than this leaves the excess to catch up on the next
     * page load — bounded cost beats complete delivery here.
     */
    protected const MAX_SHARES = 100;

    /** A share itself changed (settings, revocation, deletion). */
    public static function pingShare(AssetShare $share): void
    {
        $spaceId = self::spaceId();
        if ($spaceId === null) {
            return;
        }

        broadcast(new PublicAssetShareTouched($spaceId, $share->token));
    }

    /** A collection's content changed — ping the shares exposing it. */
    public static function pingCollection(string $collectionId): void
    {
        $spaceId = self::spaceId();
        if ($spaceId === null) {
            return;
        }

        self::ping($spaceId, self::accessibleTokens($collectionId));
    }

    /**
     * An asset changed. Smart collections resolve membership from rules and
     * selection/folder shares reference assets directly, so any asset change
     * can alter what any share shows. Evaluating that per share is unbounded —
     * instead every accessible share (capped) gets one ping, memoized per
     * request so bulk operations trigger a single burst.
     */
    public static function pingAllAccessible(): void
    {
        $spaceId = self::spaceId();
        if ($spaceId === null) {
            return;
        }

        once(function () use ($spaceId) {
            self::ping($spaceId, self::accessibleTokens());

            return true;
        });
    }

    /**
     * Tokens of shares a visitor could currently open — revoked, expired and
     * deleted shares are excluded, so no ping ever references a dead token.
     *
     * @return list<string>
     */
    public static function accessibleTokens(?string $collectionId = null): array
    {
        return AssetShare::query()
            ->when($collectionId !== null, fn ($query) => $query->where('collection_id', $collectionId))
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->limit(self::MAX_SHARES)
            ->pluck('token')
            ->all();
    }

    /**
     * @param  list<string>  $tokens
     */
    protected static function ping(string $spaceId, array $tokens): void
    {
        foreach ($tokens as $token) {
            broadcast(new PublicAssetShareTouched($spaceId, $token));
        }
    }

    protected static function spaceId(): ?string
    {
        return (request('space') ?? SpaceContext::current())?->id;
    }
}
