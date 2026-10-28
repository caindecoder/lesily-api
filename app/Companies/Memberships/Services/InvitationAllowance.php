<?php

namespace App\Companies\Memberships\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use App\Companies\Memberships\Exceptions\InvitationAllowanceExceededException;

/**
 * The actor's hourly invitation allowance, shared by single invite and resend
 * (the `invitations` throttle) and bulk invite, which spends one per row.
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
     * The middleware's own check: a counter whose window has closed is reset, never
     * read as spent.
     */
    protected function wouldExceed(string $key, int $invitations): bool
    {
        return RateLimiter::tooManyAttempts($key, self::PER_HOUR - $invitations + 1);
    }

    /**
     * The key the throttle middleware stores the `invitations` limit under:
     * md5 of the limiter name and the limit's key, the acting user's id.
     */
    protected function counterKey(User $actor): string
    {
        return md5('invitations'.$actor->getAuthIdentifier());
    }
}
