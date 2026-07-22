<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Models\User;
use Carbon\CarbonImmutable;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * UC-1-125 — View Ownership Transfer Status (slice #11 Task 2): the company's
 * Owner / Admin / Auditor read the current lifecycle state of a single
 * ownership-transfer record, company-scoped; a record from another company is
 * existence-hidden (404, never 403).
 */
class ViewOwnershipTransferStatusTest extends OwnershipTransfersTestCase
{
    // --- Sad ---

    #[Test]
    public function when_the_request_is_unauthenticated_then_it_is_rejected(): void
    {
        // Given a persisted transfer and no session
        [$company] = $this->aCompanyWithOwner();
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When its status is requested without authentication
        $response = $this->getTransferStatusAsGuest($transfer->id);

        // Then it is refused as unauthenticated
        $this->assertRefusedWith($response, ApiErrorCode::Unauthenticated);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutViewPermission')]
    public function when_the_actor_lacks_the_view_permission_then_it_is_denied(string $roleCode): void
    {
        // Given a persisted transfer and a member without transfer-view rights
        [$company] = $this->aCompanyWithOwner();
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);
        $actor = $this->aMemberWithRole($company, $roleCode);

        // When they request the status
        $response = $this->getTransferStatus($actor, $company, $transfer->id);

        // Then it is denied for lacking the view-status permission
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
    }

    /**
     * The tenant roles the matrix Module 8 denies transfer-view.
     *
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutViewPermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }

    #[Test]
    public function when_the_transfer_belongs_to_another_company_then_it_is_not_found(): void
    {
        // Given a transfer in a DIFFERENT company
        [$company, $owner] = $this->aCompanyWithOwner();
        [$otherCompany] = $this->aCompanyWithOwner();
        $foreign = $this->aTransferInState($otherCompany, User::factory()->create(), OwnershipTransferStatus::Requested);

        // When the owner requests the foreign transfer's status in their own context
        $response = $this->getTransferStatus($owner, $company, $foreign->id);

        // Then it is existence-hidden (404, not 403 — no cross-tenant leak)
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    #[Test]
    public function when_the_transfer_id_is_unknown_then_it_is_not_found(): void
    {
        // Given an owner with no such transfer
        [$company, $owner] = $this->aCompanyWithOwner();

        // When they request a non-existent id
        $response = $this->getTransferStatus($owner, $company, fake()->uuid());

        // Then it is not found
        $this->assertRefusedWith($response, ApiErrorCode::ResourceNotFound);
    }

    // --- Edge ---

    #[Test]
    #[DataProvider('providePermittedNonOwnerRoles')]
    public function when_a_permitted_non_owner_role_views_then_the_status_is_visible(string $roleCode): void
    {
        // Given a persisted transfer and a member holding a matrix-permitted view role
        [$company] = $this->aCompanyWithOwner();
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Requested);
        $viewer = $this->aMemberWithRole($company, $roleCode);

        // When they request the status
        $response = $this->getTransferStatus($viewer, $company, $transfer->id);

        // Then it is visible — admin + auditor are the ✅/👁️ rows in matrix Module 8
        $response->assertOk();
        $response->assertJsonPath('data.id', $transfer->id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function providePermittedNonOwnerRoles(): array
    {
        return [
            'admin' => ['admin'],
            'auditor' => ['auditor'],
        ];
    }

    #[Test]
    public function when_the_transfer_is_verified_then_the_status_reflects_the_extended_expiry(): void
    {
        // Given a verified transfer (its timer reset to the verified-stage window)
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aTransferInState($company, User::factory()->create(), OwnershipTransferStatus::Verified);

        // When the owner views the status
        $response = $this->getTransferStatus($owner, $company, $transfer->id);

        // Then it reports the verified stage: verified_at set, executed_at null,
        // expires_at surfacing the reset timer, and the awaiting-execution guidance
        $response->assertOk();
        $this->assertVerifiedTimeline($response, $transfer);
    }

    private function assertVerifiedTimeline(TestResponse $response, OwnershipTransferRequest $transfer): void
    {
        $response->assertJsonPath('data.status', 'verified');
        $response->assertJsonPath('data.executed_at', null);
        $this->assertNotNull($response->json('data.verified_at'));
        $this->assertSame($transfer->next_state_transition_at?->toJSON(), $response->json('data.expires_at'));
        $response->assertJsonPath('data.next_action', 'Awaiting platform admin to execute the transfer');
    }

    #[Test]
    #[DataProvider('provideTerminalStates')]
    public function when_the_transfer_is_terminal_then_the_status_remains_readable(OwnershipTransferStatus $state, string $terminatingActor): void
    {
        // Given a terminal transfer
        [$company, $owner] = $this->aCompanyWithOwner();
        $transfer = $this->aTransferInState($company, User::factory()->create(), $state);

        // When the owner views the status
        $response = $this->getTransferStatus($owner, $company, $transfer->id);

        // Then it is readable, names the terminating actor, and has a cleared timer
        $response->assertOk();
        $response->assertJsonPath('data.status', $state->value);
        $response->assertJsonPath('data.terminating_actor', $terminatingActor);
        $response->assertJsonPath('data.expires_at', null);
    }

    /**
     * @return array<string, array{OwnershipTransferStatus, string}>
     */
    public static function provideTerminalStates(): array
    {
        return [
            'executed' => [OwnershipTransferStatus::Executed, 'platform_admin'],
            'rejected' => [OwnershipTransferStatus::Rejected, 'platform_admin'],
            'cancelled' => [OwnershipTransferStatus::Cancelled, 'owner'],
            'expired' => [OwnershipTransferStatus::Expired, 'system_timer'],
        ];
    }

    // --- Happy ---

    #[Test]
    public function when_the_owner_views_a_requested_transfer_then_the_exact_status_envelope_is_returned(): void
    {
        // Given a requested transfer to a known target
        [$company, $owner] = $this->aCompanyWithOwner();
        $target = User::factory()->create(['name' => 'Alex Turner', 'email' => 'alex.turner@contoso.test']);
        $transfer = $this->aTransferRequestedAt($company, $target, CarbonImmutable::parse('2026-05-01 09:00'));

        // When the owner views the status
        $response = $this->getTransferStatus($owner, $company, $transfer->id);

        // Then the exact owner-facing envelope is returned (leak-guard)
        $response->assertOk();
        $this->assertExactRequestedStatus($response);
    }

    /**
     * The detail carries EXACTLY the owner-facing fields (leak-guard): masked
     * target email, next_action + support channel, the default SLA hint, and NO
     * verification artefacts / verifier notes / raw ids.
     */
    private function assertExactRequestedStatus(TestResponse $response): void
    {
        $this->assertSame([
            'id', 'company_id', 'status', 'request_channel', 'reason', 'requested_at',
            'verified_at', 'executed_at', 'rejected_at', 'cancelled_at', 'expired_at',
            'expires_at', 'cancellation_reason', 'expiry_source', 'terminating_actor',
            'next_action', 'expected_completion_at', 'support_channel',
            'target_user', 'requested_by',
        ], array_keys($response->json('data')));
        $response->assertJsonPath('data.target_user', ['name' => 'Alex Turner', 'email_masked' => 'a***@contoso.test']);
        $response->assertJsonPath('data.support_channel.email', 'support@lesily.com');
        $response->assertJsonPath('data.expected_completion_at', CarbonImmutable::parse('2026-05-01 09:00')->addWeekdays(5)->toJSON());
    }
}
