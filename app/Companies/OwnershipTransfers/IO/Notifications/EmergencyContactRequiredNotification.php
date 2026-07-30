<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

/**
 * SM-1-05 T4 — the incoming owner is asked to set an emergency contact.
 *
 * §S10b makes the emergency contact mandatory for OWNERS specifically, so this
 * obligation arrives with the role rather than with membership: T1 already
 * refuses a target who lacks one (`EmergencyContactRequiredException`), but a
 * transfer can sit verified for days, and the contact can be cleared in between.
 * UC-1-029 is where the new owner actually supplies it.
 *
 * On the module-local discriminated base (s151 ruling H2) like every other
 * transition's notice — the generic two-argument `SystemNotification` cannot
 * carry a transfer id, which is what left T1 and T2 emitting identical notices.
 */
class EmergencyContactRequiredNotification extends OwnershipTransferNotification
{
    protected function title(): string
    {
        return 'Add an emergency contact';
    }

    protected function body(): string
    {
        return "You are now the owner of {$this->companyName()}. Please add an emergency contact to complete the handover.";
    }
}
