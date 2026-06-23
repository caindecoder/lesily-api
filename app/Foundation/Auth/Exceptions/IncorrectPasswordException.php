<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * A password re-authentication for a sensitive 2FA change (disable UC-1-007 /
 * regenerate recovery codes UC-1-137) failed — the submitted password did not match.
 * Distinct from InvalidCredentialsException (the anti-enumeration LOGIN boundary): this
 * is an already-authenticated, identity-bound re-auth, so it carries its own
 * specifically-named code (LeaveDates InvalidCurrentPasswordException analog; Clean Code
 * E04 §13). Rendered to the §S21 401 envelope via ApiErrorCode::IncorrectPassword.
 */
class IncorrectPasswordException extends RuntimeException {}
