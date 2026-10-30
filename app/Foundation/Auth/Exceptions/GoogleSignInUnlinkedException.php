<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * A Google sign-in reached an account whose owner unlinked Google (UC-1-003 4d, G12) — only a
 * re-link from the profile turns Google sign-in back on. Rendered to the §S21 409 envelope via
 * ApiErrorCode::GoogleSignInUnlinked.
 */
class GoogleSignInUnlinkedException extends RuntimeException {}
