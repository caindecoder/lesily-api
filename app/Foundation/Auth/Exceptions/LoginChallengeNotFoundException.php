<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * The 2FA login challenge presented at /2fa/verify does not resolve — it expired
 * (5-min TTL), was never created, or was already consumed/abandoned (UC-1-005).
 * Rendered to the §S21 404 envelope via ApiErrorCode::TwoFactorChallengeNotFound;
 * the client must restart login to obtain a fresh challenge.
 */
class LoginChallengeNotFoundException extends RuntimeException {}
