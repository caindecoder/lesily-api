<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * Enrollment was started on an account that already has 2FA enabled (UC-1-006 error
 * flow 1b) — a conflict, the resource is already in the target state. Rendered to the
 * §S21 409 envelope via ApiErrorCode::TwoFactorAlreadyEnabled.
 */
class TwoFactorAlreadyEnabledException extends RuntimeException {}
