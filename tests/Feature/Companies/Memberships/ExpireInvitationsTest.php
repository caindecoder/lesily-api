<?php

namespace Tests\Feature\Companies\Memberships;

use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // Edge (boundary) — an invitation is expired only when its window elapsed
    // STRICTLY before the run cutoff; the exactly-at-cutoff row pins `<` against
    // a `<=` mutation.
    #[Test]
    #[DataProvider('cutoffBoundaryProvider')]
    public function when_the_scan_runs_then_an_invitation_is_expired_only_if_its_window_has_elapsed(
        string $runAt,
        bool $expectExpired,
    ): void {
        // Given a pending invitation whose window ends at T0 + 7d (2026-07-09 09:00:00)
        [$company, $actor] = $this->aCompanyWithMember('admin');
        $this->aPendingInvitation($company, $actor, 'invitee@contoso.lk');

        // When the daily scan runs at a given instant relative to that window
        $this->travelTo(CarbonImmutable::parse($runAt));

        // Then the invitation is expired only if its window elapsed before the cutoff
        $expected = $expectExpired ? '1 invitation(s) expired.' : '0 invitation(s) expired.';
        $this->runExpireInvitations()->expectsOutputToContain($expected)->assertSuccessful();
    }

    /**
     * expires_at = T0 (2026-07-02 09:00:00) + 7d = 2026-07-09 09:00:00. The
     * exactly-at row makes `expires_at < now()` strictly false — the row that
     * kills a `<` -> `<=` mutation.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function cutoffBoundaryProvider(): array
    {
        return [
            'a day before the window ends' => ['2026-07-08 09:00:00', false],
            'exactly when the window ends' => ['2026-07-09 09:00:00', false],
            'a second after the window ends' => ['2026-07-09 09:00:01', true],
        ];
    }

    // Edge (idempotency) — once a row is expired it carries an expired_at stamp;
    // a later daily scan excludes it (whereNull expired_at), so nothing is
    // re-expired and the event is recorded exactly once.
    #[Test]
    public function when_an_already_expired_invitation_is_rescanned_then_it_is_not_re_expired(): void
    {
        // Given a pending invitation an earlier daily scan already expired
        [$company, $actor] = $this->aCompanyWithMember('admin');
        $this->aPendingInvitation($company, $actor, 'invitee@contoso.lk');
        $this->travel(8)->days();
        $this->runExpireInvitations()->assertSuccessful();

        // When the daily scan runs again
        // Then nothing is re-expired and the expiry event was recorded exactly once
        $this->runExpireInvitations()->expectsOutputToContain('0 invitation(s) expired.')->assertSuccessful();
        $this->assertInvitationExpired($company, 'invitee@contoso.lk');
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

    // Happy — one daily run expires EVERY due invitation, not just the first,
    // and leaves an in-window invitation pending (selectivity + one-to-many).
    #[Test]
    public function when_multiple_invitations_are_due_then_only_the_due_ones_are_expired(): void
    {
        // Given two invitations past their window and one still inside it
        [$company, $actor] = $this->aCompanyWithMember('admin');
        $this->aPendingInvitation($company, $actor, 'due-one@contoso.lk');
        $this->aPendingInvitation($company, $actor, 'due-two@contoso.lk');
        $this->travel(8)->days();
        $this->aPendingInvitation($company, $actor, 'fresh@contoso.lk');

        // When the daily scan runs
        $this->runExpireInvitations()->expectsOutputToContain('2 invitation(s) expired.')->assertSuccessful();

        // Then only the two due invitations are expired; the fresh one stays pending
        $this->assertInvitationExpired($company, 'due-one@contoso.lk');
        $this->assertInvitationExpired($company, 'due-two@contoso.lk');
        $this->assertInvitationStillPending($company, 'fresh@contoso.lk');
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

    /**
     * The invitation for ($company, $email) was NOT expired: no expired_at stamp
     * and no UserInvitationExpired stored event (the LD state-unchanged
     * discipline — a sad/excluded row changes nothing).
     */
    protected function assertInvitationStillPending(Company $company, string $email): void
    {
        $invitation = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->sole();

        $this->assertNull($invitation->expired_at);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', UserInvitationExpired::class)
            ->where('event_properties->membershipId', $invitation->getKey())
            ->count());
    }
}
