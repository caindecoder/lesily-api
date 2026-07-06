<?php

namespace Tests\Feature\Companies\Memberships;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Events\UserInvitationExpired;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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

    // Edge — an invitation accepted before its window elapsed is no longer
    // pending, so the scan excludes it (accepted_at is set).
    #[Test]
    public function when_an_accepted_invitation_past_expiry_then_it_is_not_expired(): void
    {
        // Given an accepted invitation now past its original 7-day window
        [$company, $actor] = $this->aCompanyWithMember('admin');
        $this->anAcceptedMembership($company, $actor, 'joiner@contoso.lk');
        $this->travel(8)->days();

        // When the daily scan runs
        // Then nothing is expired — an accepted invitation is never expirable
        $this->runExpireInvitations()->expectsOutputToContain('0 invitation(s) expired.')->assertSuccessful();
    }

    // Edge — a revoked invitation is dead already, so the scan excludes it
    // (revoked_at is set).
    #[Test]
    public function when_a_revoked_invitation_past_expiry_then_it_is_not_expired(): void
    {
        // Given a revoked invitation now past its window
        [$company, $actor] = $this->aCompanyWithMember('admin');
        $this->aRevokedInvitation($company, $actor, 'revoked@contoso.lk');
        $this->travel(8)->days();

        // When the daily scan runs
        // Then nothing is expired — a revoked invitation is never expirable
        $this->runExpireInvitations()->expectsOutputToContain('0 invitation(s) expired.')->assertSuccessful();
    }

    // Happy — a pending invitation past its 7-day window is expired: the scan
    // records UserInvitationExpired, the projector stamps expired_at, and the
    // command reports how many it expired.
    #[Test]
    public function when_a_pending_invitation_is_past_expiry_then_it_is_expired(): void
    {
        // Given a pending invitation now past its 7-day window
        [$company, $actor] = $this->aCompanyWithMember('admin');
        $this->aPendingInvitation($company, $actor, 'invitee@contoso.lk');
        $this->travel(8)->days();

        // When the daily scan runs
        $this->runExpireInvitations()->expectsOutputToContain('1 invitation(s) expired.')->assertSuccessful();

        // Then the invitation is marked expired (event-sourced + projected)
        $this->assertInvitationExpired($company, 'invitee@contoso.lk');
    }

    protected function runExpireInvitations(): PendingCommand
    {
        $command = $this->artisan('invitations:expire');
        assert($command instanceof PendingCommand);

        return $command;
    }

    /**
     * The invitation for ($company, $email) was expired the event-sourced way:
     * expired_at stamped at the run cutoff (±10s), and exactly one
     * UserInvitationExpired stored event carries this row's id (amendment R — a
     * direct read-model write must fail this assertion).
     */
    protected function assertInvitationExpired(Company $company, string $email): void
    {
        $invitation = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->sole();

        $this->assertNotNull($invitation->expired_at);
        $this->assertEqualsWithDelta(now()->getTimestamp(), $invitation->expired_at->getTimestamp(), 10);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserInvitationExpired::class)
            ->where('event_properties->membershipId', $invitation->getKey())
            ->count());
    }
}
