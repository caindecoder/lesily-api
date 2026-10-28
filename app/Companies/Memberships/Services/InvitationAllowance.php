<?php

namespace App\Companies\Memberships\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use App\Companies\Memberships\Exceptions\InvitationAllowanceExceededException;

/**
 * The actor's hourly invitation allowance, spent by single invite and resend (one
 * each) and bulk invite (one per row). Keyed by the acting user, not the IP: it caps
 * one account's invite email without a shared office IP starving other admins
 * (D9/OQ-1-029; questionnaire §S14 special rate limits).
 */
class InvitationAllowance
{
    public const int PER_HOUR = 20;

    protected const int HOUR_IN_SECONDS = 3600;

    /**
     * @throws InvitationAllowanceExceededException
     */
    public function spend(User $actor, int $invitations): void
    {
        $key = $this->counterKey($actor);

        if ($this->wouldExceed($key, $invitations)) {
            throw new InvitationAllowanceExceededException(RateLimiter::availableIn($key));
        }

        RateLimiter::increment($key, self::HOUR_IN_SECONDS, $invitations);
    }

    /**
     * A counter whose window has closed is reset, never read as spent.
     */
    protected function wouldExceed(string $key, int $invitations): bool
    {
        return RateLimiter::tooManyAttempts($key, self::PER_HOUR - $invitations + 1);
    }

    protected function counterKey(User $actor): string
    {
        return sprintf('invitations:%s', $actor->getAuthIdentifier());
    }
}
