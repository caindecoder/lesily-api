<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * A 2FA management action (disable UC-1-007 / regenerate recovery codes UC-1-137)
 * was attempted on an account that has no 2FA enabled — there is nothing to act on.
 * Rendered to the §S21 422 envelope via ApiErrorCode::TwoFactorNotEnabled.
 */
class TwoFactorNotEnabledException extends RuntimeException {}
