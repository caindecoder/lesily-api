<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * An unlink (UC-1-003 P2a) on an account with no Google link — there is nothing to unlink.
 * Rendered to the §S21 422 envelope via ApiErrorCode::GoogleNotLinked.
 */
class GoogleNotLinkedException extends RuntimeException {}
