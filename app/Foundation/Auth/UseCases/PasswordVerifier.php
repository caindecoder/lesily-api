<?php

namespace App\Foundation\Auth\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Foundation\Auth\Exceptions\IncorrectPasswordException;

/**
 * Re-authenticates an already-signed-in user by their current password before a sensitive,
 * identity-bound action — the shared current-password gate behind 2FA disable (UC-1-007),
 * recovery-code regeneration (UC-1-137), logout-all (UC-1-011) and — next — change password
 * (UC-1-016). A concrete collaborator with NO interface (the TokenRevoker rationale): Hash is
 * the single in-process implementation and tests drive the real check through the HTTP seam,
 * so a statist feature test is the right seam, not a mock (E23). Throws IncorrectPasswordException
 * (the §S21 401 envelope) on mismatch; returns silently on success.
 */
class PasswordVerifier
{
    public function verify(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw new IncorrectPasswordException;
        }
    }
}
