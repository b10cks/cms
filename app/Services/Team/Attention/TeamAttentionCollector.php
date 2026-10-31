<?php

namespace App\Services\Team\Attention;

use App\Models\User;
use App\Services\Team\Attention\Checks\ExpiringAccess;
use App\Services\Team\Attention\Checks\PendingJoinRequests;
use App\Services\Team\Attention\Checks\SamlCertificateExpiry;
use App\Services\Team\Attention\Checks\StaleInvites;
use App\Services\Team\Attention\Checks\TeamsWithoutOwner;
use App\Services\Team\TeamScope;

/**
 * Builds the "Needs attention" list of the team overview from the checks
 * below, in this order. Add a check by implementing AttentionCheck and
 * listing its class here.
 */
class TeamAttentionCollector
{
    /** @var array<int, class-string<AttentionCheck>> */
    public const CHECKS = [
        PendingJoinRequests::class,
        ExpiringAccess::class,
        StaleInvites::class,
        SamlCertificateExpiry::class,
        TeamsWithoutOwner::class,
    ];

    /**
     * @return array<int, AttentionItem>
     */
    public function collect(TeamScope $scope, User $actor): array
    {
        $items = [];
        foreach (self::CHECKS as $check) {
            array_push($items, ...app($check)->collect($scope, $actor));
        }

        return $items;
    }
}
