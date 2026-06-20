<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * A login attempt failed the credential check — unknown email OR wrong password.
 * Both cases throw THIS single exception (and its render is byte-identical) so the
 * response never discloses which was wrong (anti-enumeration, UC-1-002 3a/3b).
 * Rendered to the §S21 401 envelope reusing ApiErrorCode::Unauthenticated.
 */
class InvalidCredentialsException extends RuntimeException {}
