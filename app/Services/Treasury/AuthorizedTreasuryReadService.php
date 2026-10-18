<?php

namespace App\Services\Treasury;

use App\Models\User;

/**
 * Façade trésorerie : applique toujours le périmètre RBAC avant lecture.
 */
final class AuthorizedTreasuryReadService
{
    public function __construct(
        private TreasuryReadService $treasuryReadService,
        private TreasuryAccessPolicy $accessPolicy,
    ) {}

    public function reportForUser(User $user, TreasuryReadFilter $filter): TreasuryReport
    {
        $scope = $this->accessPolicy->queryScopeFor($user);

        return $this->treasuryReadService->report($filter, $scope);
    }
}
