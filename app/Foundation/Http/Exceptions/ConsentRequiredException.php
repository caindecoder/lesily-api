<?php

namespace App\Foundation\Http\Exceptions;

use Exception;

/**
 * EnsureCurrentConsent's refusal — the member has not accepted the live Terms of
 * Service and Privacy Policy versions for this company (UC-1-069). Maps to
 * error_code CONSENT_REQUIRED.
 */
class ConsentRequiredException extends Exception
{
    public function __construct()
    {
        parent::__construct('Our Terms of Service or Privacy Policy has been updated. Please review and accept to continue.');
    }
}
