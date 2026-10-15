<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use Illuminate\Support\Facades\DB;
use App\Foundation\Auth\Aggregates\User;

/**
 * Marks an owner's email verified (UC-1-001 alt-6a). The use-case ring (E07):
 * the controller is a thin delivery appendix and never touches the aggregate
 * directly. The signed-link signature is the validated input, so the verified
 * user id arrives already-trusted; this is the seam where any post-verify policy
 * (e.g. a future email-change hash re-check) would live.
 */
class VerifyEmailInteractor
{
    public function execute(string $userId): void
    {
        DB::transaction(fn () => User::retrieve($userId)->verifyEmail()->persist());
    }
}
