<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * A Pro/Enterprise account already at its concurrent-device limit attempted to open a
 * fourth session — at login or 2FA-verify (UC-1-002 alt-5a). Thrown by DeviceLimitGuard
 * before any token is minted, so the rejection leaves the existing sessions untouched.
 * Message-less — the canonical copy lives in the §S21 render handler (mirrors
 * IncorrectPasswordException). Rendered to the 403 envelope via ApiErrorCode::DeviceLimitReached.
 */
class DeviceLimitReachedException extends RuntimeException {}
