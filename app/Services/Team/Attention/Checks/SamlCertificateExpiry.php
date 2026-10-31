<?php

namespace App\Services\Team\Attention\Checks;

use App\Models\Management\TeamSamlProvider;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use App\Services\Team\Attention\AttentionCheck;
use App\Services\Team\Attention\AttentionItem;
use App\Services\Team\TeamScope;
use Illuminate\Support\Carbon;

/**
 * Identity provider certificates in the subtree that expire within 30 days.
 * `count` is the number of days left (0 once expired). Certificates that do
 * not parse are skipped.
 */
class SamlCertificateExpiry implements AttentionCheck
{
    public const DAYS = 30;

    public function __construct(private readonly AuthorizationService $authorization) {}

    public function collect(TeamScope $scope, User $actor): array
    {
        if (! $this->authorization->canInTeam($actor, $scope->team, 'team.saml.manage')) {
            return [];
        }

        return TeamSamlProvider::query()
            ->with('team:id,name')
            ->whereIn('team_id', $scope->teamIds)
            ->where('enabled', true)
            ->get()
            ->map(function (TeamSamlProvider $provider) {
                $expiresAt = self::certificateExpiry((string) $provider->idp_x509_cert);
                if (! $expiresAt || $expiresAt->isAfter(now()->addDays(self::DAYS))) {
                    return null;
                }

                return new AttentionItem(
                    type: 'saml_certificate_expiring',
                    count: max(0, (int) now()->diffInDays($expiresAt, false)),
                    details: [$provider->team?->name ?? ''],
                    target: ['name' => 'team-saml', 'params' => ['team' => $provider->team_id]],
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** Accepts a PEM block or the bare base64 body an IdP usually hands out. */
    public static function certificateExpiry(string $certificate): ?Carbon
    {
        $certificate = trim($certificate);
        if ($certificate === '') {
            return null;
        }

        if (! str_contains($certificate, 'BEGIN CERTIFICATE')) {
            $body = preg_replace('/\s+/', '', $certificate);
            $certificate = "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----";
        }

        $parsed = @openssl_x509_parse($certificate);

        return is_array($parsed) && isset($parsed['validTo_time_t'])
            ? Carbon::createFromTimestamp($parsed['validTo_time_t'])
            : null;
    }
}
