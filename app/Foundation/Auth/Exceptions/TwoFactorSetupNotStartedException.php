<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * A 2FA confirmation arrived with no enrollment in progress — the staged secret
 * expired or enrollment was never started (UC-1-006 error flow). Rendered to the
 * §S21 422 envelope via ApiErrorCode::TwoFactorSetupNotStarted.
 */
class TwoFactorSetupNotStartedException extends RuntimeException {}
