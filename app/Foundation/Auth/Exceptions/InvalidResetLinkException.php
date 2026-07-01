<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/**
 * A password-reset submission carried a token the broker rejected — never issued, expired (>60 min),
 * or already used. A consumed token is deleted, so "expired" and "used" are indistinguishable; the
 * three cases collapse to this one exception → the §S21 422 RESET_LINK_INVALID envelope, which also
 * avoids leaking token state. Raised by ResetPasswordInteractor on a non-success broker status.
 */
class InvalidResetLinkException extends RuntimeException {}
