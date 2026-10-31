<?php

namespace App\Services\Team\Attention;

use App\Models\User;
use App\Services\Team\TeamScope;

/**
 * One kind of "Needs attention" item on the team overview. Implementations
 * check the actor's abilities themselves and return nothing they may not see.
 * Register a new check in TeamAttentionCollector::CHECKS.
 */
interface AttentionCheck
{
    /**
     * @return array<int, AttentionItem>
     */
    public function collect(TeamScope $scope, User $actor): array;
}
