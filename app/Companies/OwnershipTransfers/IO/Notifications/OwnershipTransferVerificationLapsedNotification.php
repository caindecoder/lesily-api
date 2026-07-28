<?php

namespace App\Companies\OwnershipTransfers\IO\Notifications;

/**
 * SM-1-05 T9 only — the admin who verified a transfer is told it expired before
 * anyone executed it.
 *
 * This notice is the whole of UC-1-117's asymmetry with UC-1-116. In the
 * pre-verification window no admin had begun review, so there is nobody whose
 * work lapsed; here an admin has already collected artefacts and recorded
 * verification notes, and is the one person able to follow up with the requester.
 *
 * A separate class rather than a branch inside the requester's notice: the two
 * have different audiences and different copy, which is the module's H2 idiom of
 * one thin subclass per transition-and-audience. Folding them would put a
 * recipient conditional inside a notification — the shape OQ-1-144 was raised
 * about.
 */
class OwnershipTransferVerificationLapsedNotification extends OwnershipTransferNotification
{
    protected function title(): string
    {
        return 'Verified ownership transfer expired';
    }

    /**
     * Names the COMPANY for the reason every sibling does — the email leg
     * carries a subject and a body only, and an admin watching many tenants
     * cannot otherwise tell which verification lapsed.
     */
    protected function body(): string
    {
        return "The ownership transfer you verified for {$this->companyName()} expired before it was executed. "
            .'No ownership changed, and a fresh request would need verifying again.';
    }
}
