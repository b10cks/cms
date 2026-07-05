<?php

namespace App\Services\Asset;

use App\Models\Space\AssetShare;

/**
 * Stateless, short-lived access tokens proving a password-protected share was
 * unlocked. Format: base64url("{shareId}|{expiresTimestamp}|{hmac}") where the
 * hmac is a sha256 over "{shareId}|{expiresTimestamp}" keyed with the app key
 * and the share's current password hash — so rotating or removing the password
 * immediately invalidates every outstanding token. No sessions, no DB rows —
 * the token is only meaningful for the one share.
 */
class ShareAccessTokenService
{
    public function issue(AssetShare $share, ?int $ttlMinutes = null): string
    {
        $ttlMinutes ??= (int) config('asset_distribution.access_token_ttl_minutes', 60);
        $expires = now()->addMinutes($ttlMinutes)->getTimestamp();

        $payload = "{$share->id}|{$expires}";

        return $this->base64UrlEncode("{$payload}|{$this->signature($share, $payload)}");
    }

    public function verify(AssetShare $share, ?string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        $decoded = $this->base64UrlDecode($token);

        if ($decoded === null) {
            return false;
        }

        $parts = explode('|', $decoded);

        if (count($parts) !== 3) {
            return false;
        }

        [$shareId, $expires, $signature] = $parts;

        if (! hash_equals($share->id, $shareId)) {
            return false;
        }

        if (! ctype_digit($expires) || (int) $expires < now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->signature($share, "{$shareId}|{$expires}"), $signature);
    }

    private function signature(AssetShare $share, string $payload): string
    {
        // The current password hash is part of the keyed message so a password
        // change (or removal) invalidates previously issued tokens.
        $passwordFingerprint = hash('sha256', (string) $share->password);

        return hash_hmac('sha256', "asset-share-access|{$payload}|{$passwordFingerprint}", (string) config('app.key'));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
