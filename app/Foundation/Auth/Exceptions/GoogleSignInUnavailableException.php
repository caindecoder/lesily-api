<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;

/** Google's signing keys could not be fetched, so no token can be checked right now. */
class GoogleSignInUnavailableException extends RuntimeException {}
