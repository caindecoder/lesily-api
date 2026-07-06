<?php

namespace App\Companies\Memberships\UseCases\Repositories;

use Carbon\CarbonInterface;

interface InvitationRepositoryInterface
{
    /**
     * The ids of pending invitations whose window has elapsed before $asOf —
     * unaccepted, unrevoked, and not already expired — each one the daily
     * `invitations:expire` scan (T5) must expire exactly once.
     *
     * @return array<int, string>
     */
    public function listExpirablePending(CarbonInterface $asOf): array;
}
