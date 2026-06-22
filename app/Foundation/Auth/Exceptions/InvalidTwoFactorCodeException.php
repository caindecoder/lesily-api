<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * The TOTP code submitted at 2FA confirm did not verify against the staged secret
 * (UC-1-006 error flow 4a). Rendered to the §S21 422 envelope via
 * ApiErrorCode::InvalidTwoFactorCode.
 */
class InvalidTwoFactorCodeException extends RuntimeException {}
