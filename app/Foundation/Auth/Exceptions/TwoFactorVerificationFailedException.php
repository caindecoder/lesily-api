<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * The second factor submitted at /2fa/verify matched neither the TOTP secret nor any
 * remaining recovery code (UC-1-005 error flows 4a/3a). One exception covers both
 * causes: the single `code` field can't disclose which factor the user intended, so the
 * response stays uniform. Rendered to the §S21 401 envelope via
 * ApiErrorCode::TwoFactorVerificationFailed; the challenge keeps counting attempts.
 */
class TwoFactorVerificationFailedException extends RuntimeException {}
