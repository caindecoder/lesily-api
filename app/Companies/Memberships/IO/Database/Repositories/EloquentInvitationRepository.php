<?php

namespace App\Companies\Memberships\IO\Database\Repositories;

use Carbon\CarbonInterface;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\UseCases\Repositories\InvitationRepositoryInterface;

class EloquentInvitationRepository implements InvitationRepositoryInterface
{
    /**
     * @return array<int, string>
     */
    public function listExpirablePending(CarbonInterface $asOf): array
    {
        return Invitation::query()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->whereNull('expired_at')
            ->where('expires_at', '<', $asOf)
            ->pluck('id')
            ->all();
    }
}
