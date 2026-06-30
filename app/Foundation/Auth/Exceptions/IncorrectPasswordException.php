<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * A current-password re-authentication for a sensitive, identity-bound action failed — the
 * submitted password did not match. Raised centrally by PasswordVerifier, the shared gate
 * behind 2FA disable (UC-1-007), recovery-code regeneration (UC-1-137) and logout-all (UC-1-011).
 * Distinct from InvalidCredentialsException (the anti-enumeration LOGIN boundary): this is an
 * already-authenticated, identity-bound re-auth, so it carries its own specifically-named code
 * (LeaveDates InvalidCurrentPasswordException analog; Clean Code E04 §13). Rendered to the
 * §S21 401 envelope via ApiErrorCode::IncorrectPassword.
 */
class IncorrectPasswordException extends RuntimeException {}
