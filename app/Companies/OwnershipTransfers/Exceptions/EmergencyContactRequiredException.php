<?php

namespace App\Companies\OwnershipTransfers\Exceptions;

use Exception;

/**
 * The transfer target has never set an emergency contact — §S10b makes it
 * mandatory for owners, so the incoming owner must have one BEFORE the request
 * enters the verification queue (UC-1-028 T1 guard; api-conventions §8.4,
 * approved s145 as the 422 EMERGENCY_CONTACT_REQUIRED arm).
 */
class EmergencyContactRequiredException extends Exception
{
    protected $message = 'The target user must set their emergency contact before an ownership transfer can be requested.';
}
