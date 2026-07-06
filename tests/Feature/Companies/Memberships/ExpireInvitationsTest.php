<?php

namespace Tests\Feature\Companies\Memberships;

use Carbon\CarbonImmutable;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;

/**
 * T5 of the User state machine — the daily `invitations:expire` scan expires
 * pending invitations past their 7-day window (no UC, System actor). Modelled on
 * the built `trials:expire` chain. Clock fixed in setUp so `aPendingInvitation`
 * stamps a known `expires_at` (T0 + 7d = 2026-07-09 09:00:00).
 */
class ExpireInvitationsTest extends MembershipsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));
    }

    // Sad — a pending invitation still inside its 7-day window is not due, so the
    // daily scan expires nothing (the `expires_at < now()` selection excludes it).
    #[Test]
    public function when_a_pending_invitation_is_not_yet_expired_then_it_is_left_pending(): void
    {
        // Given a pending invitation still within its window
        [$company, $actor] = $this->aCompanyWithMember('admin');
        $this->aPendingInvitation($company, $actor, 'invitee@contoso.lk');

        // When the daily scan runs at T0 (still inside the window)
        // Then nothing is expired
        $this->runExpireInvitations()->expectsOutputToContain('0 invitation(s) expired.')->assertSuccessful();
    }

    protected function runExpireInvitations(): PendingCommand
    {
        $command = $this->artisan('invitations:expire');
        assert($command instanceof PendingCommand);

        return $command;
    }
}
