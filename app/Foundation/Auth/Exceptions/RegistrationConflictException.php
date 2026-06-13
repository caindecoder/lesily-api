<?php

namespace App\Foundation\Auth\Exceptions;

use RuntimeException;
use App\Foundation\Http\Responses\ApiErrorCode;

/**
 * A registration carried a globally-unique identifier that is already in use.
 * The carried ApiErrorCode names the offending field (email / BR / EPF) so the
 * render handler maps it to a field-specific 409 (api-conventions §6: a duplicate
 * is a well-formed value conflicting with current state, not malformed input).
 */
class RegistrationConflictException extends RuntimeException
{
    private function __construct(public readonly ApiErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function forEmail(): self
    {
        return new self(ApiErrorCode::EmailRegistered, 'This email is already registered. Try logging in.');
    }

    public static function forBrNumber(): self
    {
        return new self(ApiErrorCode::BrNumberRegistered, 'This BR number is already registered.');
    }

    public static function forEpfNumber(): self
    {
        return new self(ApiErrorCode::EpfNumberRegistered, 'This EPF number is already registered.');
    }
}
