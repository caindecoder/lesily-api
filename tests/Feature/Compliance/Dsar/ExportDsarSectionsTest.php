<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\OwnershipTransferCase;
use App\Companies\Memberships\Entities\Invitation;
use App\Foundation\AuditTrail\Entities\AuditEntry;

class ExportDsarSectionsTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test]
    public function when_the_member_belongs_to_two_companies_then_only_this_companys_data_is_exported(): void
    {
        // Given a member who joined another company first, under another role, with consent, an invitation, a transfer, a case and an audited submission in each — beside a co-worker's consent here
        $member = User::factory()->create();
        $other = Company::factory()->create();
        $other->users()->attach($member, ['role_id' => $this->seededRole('admin')->getKey()]);
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $this->aConsentRecordedBy($member, $this->company);
        $this->aConsentRecordedBy($member, $other);
        foreach ([$this->company, $other] as $company) {
            $this->anInvitationTo($member, $company);
            $this->anOwnershipTransfer($company, $this->aCompanyMember($company), $member);
            OwnershipTransferCase::factory()->forCompany($company)->proposedOwner($member)->create();
        }
        $this->aConsentRecordedBy($this->aCompanyMember($this->company), $this->company);
        $this->submitDsarRequest($member, $other)->assertCreated();
        $this->idempotencyKey = (string) Str::uuid();
        $request = $this->requestFrom($this->submitDsarRequest($member, $this->company));

        // When their export here is built
        $this->runTheExportFor($request);

        // Then every section holds this company's data alone
        $this->assertExportScopedTo($this->exportedData($request), $this->company, $member);
    }

    #[Test]
    public function when_co_workers_have_their_own_sign_ins_invitations_and_transfers_then_none_of_theirs_is_exported(): void
    {
        // Given a member beside a co-worker who signed in, was invited and is named on a case, and a transfer between two others
        $member = $this->aCompanyMember($this->company);
        $coWorker = $this->aCompanyMember($this->company);
        $this->aSignInFor($coWorker, 'iPhone', now()->addDays(90));
        $this->anInvitationTo($coWorker, $this->company);
        OwnershipTransferCase::factory()->forCompany($this->company)->proposedOwner($coWorker)->create();
        $this->anOwnershipTransfer($this->company, $this->aCompanyMember($this->company), $coWorker);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When the member's export is built
        $this->runTheExportFor($request);

        // Then none of the others' sign-ins, invitations, transfers or cases is theirs
        $exported = $this->exportedData($request);
        $this->assertSame(
            [[], [], ['transfers' => [], 'cases' => []]],
            [$exported['sign_ins'], $exported['invitations'], $exported['ownership_transfers']],
        );
    }

    #[Test]
    public function when_the_export_is_built_then_no_secret_is_exported(): void
    {
        // Given a member with a password, a remember token, two-factor sign-in, a sign-in token, an invitation and a verified-then-rejected case
        $member = User::factory()->withTwoFactor()->create();
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $signIn = $this->aSignInFor($member, 'iPhone', now()->addDays(90));
        $invitationId = $this->anInvitationTo($member, $this->company);
        $case = OwnershipTransferCase::factory()->forCompany($this->company)->proposedOwner($member)->verified()->closedRejected()->create();
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then none of their secrets, token hashes, case notes or prose reasons appears anywhere in it
        $this->assertNoSecretOf($member, $this->exportedData($request), [
            $signIn->token,
            Invitation::query()->findOrFail($invitationId)->token_hash,
            $case->verification_notes,
            $case->rejection_reason,
        ]);
    }

    #[Test]
    public function when_the_export_is_built_then_no_temporary_file_outlives_it(): void
    {
        // Given a member's own request and an empty working directory for the archive
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);
        $workDirectory = $this->anArchiveWorkingInAnEmptyDirectory();

        // When their export is built
        $this->runTheExportFor($request);

        // Then the ZIP reached the dsar disk and no copy of their data is left behind
        $this->assertExportBuiltWithoutLeftovers($request, $workDirectory);
    }

    #[Test]
    public function when_the_export_is_built_then_its_data_is_indented_for_reading(): void
    {
        // Given
        $request = $this->aSubmittedRequest($this->company, $this->aCompanyMember($this->company));

        // When
        $this->runTheExportFor($request);

        // Then
        $this->assertStringStartsWith("{\n    \"profile\": {\n        \"id\": ", $this->exportedJsonOf($request));
    }

    #[Test]
    public function when_a_section_has_no_entries_then_it_is_exported_empty(): void
    {
        // Given a member who never consented here and acted in nothing audited
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then those sections are present and empty
        $exported = $this->exportedData($request);
        $this->assertSame([[], []], [$exported['consent_records'], $exported['audit_trail']]);
    }

    #[Test]
    public function when_an_invitation_went_through_several_rounds_then_every_round_is_exported(): void
    {
        // Given a member whose invitation lapsed, went out again as an admin, was resent and then revoked
        $member = $this->aCompanyMember($this->company);
        $invitationId = $this->anInvitationTo($member, $this->company);
        $this->expireTheInvitation($invitationId);
        $this->inviteAgain($invitationId, 'admin');
        $this->resendTheInvitation($invitationId);
        $this->revokeTheInvitation($invitationId);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then every round is exported in order
        $this->assertSame([[
            'timeline' => [
                ['event' => 'invited', 'role' => 'employee', 'at' => now()->toISOString()],
                ['event' => 'expired', 'role' => null, 'at' => now()->toISOString()],
                ['event' => 'invited', 'role' => 'admin', 'at' => now()->toISOString()],
                ['event' => 'resent', 'role' => null, 'at' => now()->toISOString()],
                ['event' => 'revoked', 'role' => null, 'at' => now()->toISOString()],
            ],
        ]], $this->exportedData($request)['invitations']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_audit_section_holds_the_entries_they_acted_in(): void
    {
        // Given a member whose own submission is audited, beside another member's audited submission
        $member = $this->aCompanyMember($this->company);
        $request = $this->requestFrom($this->submitDsarRequest($member, $this->company));
        $this->idempotencyKey = (string) Str::uuid();
        $this->submitDsarRequest($this->aCompanyMember($this->company), $this->company)->assertCreated();

        // When their export is built
        $this->runTheExportFor($request);

        // Then only the entry they acted in is exported
        $entry = AuditEntry::query()->where('actor_user_id', $member->getKey())->sole();
        $this->assertSame([[
            'recorded_at' => $entry->recorded_at->toISOString(),
            'action' => 'dsar_requested',
            'description' => $entry->description,
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'ip_address' => $entry->ip_address,
            'user_agent' => $entry->user_agent,
        ]], $this->exportedData($request)['audit_trail']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_consent_section_holds_their_consent_records(): void
    {
        // Given a member who agreed to the terms and the privacy policy here
        $member = $this->aCompanyMember($this->company);
        $this->aConsentRecordedBy($member, $this->company);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then
        $this->assertSame([
            ['policy_type' => 'terms', 'version' => '2026-03', 'ip_address' => '203.0.113.7', 'recorded_at' => now()->toISOString()],
            ['policy_type' => 'privacy', 'version' => '2026-04', 'ip_address' => '203.0.113.7', 'recorded_at' => now()->toISOString()],
        ], $this->exportedData($request)['consent_records']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_membership_section_holds_their_role_and_emergency_contact(): void
    {
        // Given an HR officer who named an emergency contact
        $member = User::factory()->create();
        $this->company->users()->attach($member, [
            'role_id' => $this->seededRole('hr_officer')->getKey(),
            'emergency_contact_name' => 'Nimali Perera',
            'emergency_contact_email' => 'nimali@example.lk',
            'emergency_contact_phone' => '+94771234567',
            'emergency_contact_relationship' => 'Sister',
        ]);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then
        $this->assertSame([
            'company' => ['id' => $this->company->getKey(), 'name' => $this->company->name],
            'role' => 'hr_officer',
            'joined_at' => now()->toISOString(),
            'emergency_contact' => [
                'name' => 'Nimali Perera',
                'email' => 'nimali@example.lk',
                'phone' => '+94771234567',
                'relationship' => 'Sister',
            ],
        ], $this->exportedData($request)['membership']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_profile_section_holds_the_persons_profile(): void
    {
        // Given a member with two-factor sign-in, an unverified email, who last signed in yesterday
        $member = User::factory()->unverified()->withTwoFactor()->lastLoggedAt('2026-09-30 18:00:00')->create();
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then
        $this->assertSame([
            'id' => $member->getKey(),
            'name' => $member->name,
            'email' => $member->email,
            'locale' => $member->locale,
            'email_verified_at' => null,
            'last_logged_at' => '2026-09-30T18:00:00.000000Z',
            'created_at' => $member->created_at?->toISOString(),
            'two_factor_enabled' => true,
        ], $this->exportedData($request)['profile']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_sign_ins_section_holds_every_token_they_signed_in_with(): void
    {
        // Given a member whose laptop sign-in has lapsed and whose phone sign-in is live
        $member = $this->aCompanyMember($this->company);
        $this->aSignInFor($member, 'Chrome on Windows', now()->subDay());
        $this->aSignInFor($member, 'iPhone', now()->addDays(90));
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then both sign-ins are exported, oldest first
        $this->assertSame([
            ['device_name' => 'Chrome on Windows', 'signed_in_at' => now()->toISOString(), 'last_used_at' => now()->subHour()->toISOString(), 'expires_at' => now()->subDay()->toISOString()],
            ['device_name' => 'iPhone', 'signed_in_at' => now()->toISOString(), 'last_used_at' => now()->subHour()->toISOString(), 'expires_at' => now()->addDays(90)->toISOString()],
        ], $this->exportedData($request)['sign_ins']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_invitations_section_holds_the_invitation_that_brought_them_in(): void
    {
        // Given a member who joined through an invitation
        $member = User::factory()->create();
        $invitationId = $this->anInvitationTo($member, $this->company);
        $this->acceptTheInvitation($invitationId, $member);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then the invitation is exported with its timeline
        $this->assertSame([[
            'timeline' => [
                ['event' => 'invited', 'role' => 'employee', 'at' => now()->toISOString()],
                ['event' => 'joined', 'role' => 'employee', 'at' => now()->toISOString()],
            ],
        ]], $this->exportedData($request)['invitations']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_ownership_section_holds_the_transfers_and_cases_they_took_part_in(): void
    {
        // Given a member who received the company, was the owner a rejected case expected, handed it on, and is the proposed owner on a verified case
        $member = $this->aCompanyMember($this->company);
        $this->anOwnershipTransfer($this->company, $this->aCompanyMember($this->company), $member);
        $rejected = OwnershipTransferCase::factory()->forCompany($this->company->refresh())->closedRejected()->create();
        $this->anOwnershipTransfer($this->company, $member, $this->aCompanyMember($this->company));
        $verified = OwnershipTransferCase::factory()->forCompany($this->company)->proposedOwner($member)->verified()->create();
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then both transfers and both cases are exported
        $this->assertSame([
            'transfers' => [
                ['transferred_at' => now()->toISOString(), 'role' => 'new_owner', 'source' => 'self_service'],
                ['transferred_at' => now()->toISOString(), 'role' => 'previous_owner', 'source' => 'self_service'],
            ],
            'cases' => [
                [
                    'status' => 'closed',
                    'outcome' => 'rejected',
                    'rejection_reason_code' => 'identity_failed',
                    'requested_context' => $rejected->requested_context,
                    'opened_at' => now()->toISOString(),
                    'verified_at' => null,
                    'executed_at' => null,
                ],
                [
                    'status' => 'verified',
                    'outcome' => null,
                    'rejection_reason_code' => null,
                    'requested_context' => $verified->requested_context,
                    'opened_at' => now()->toISOString(),
                    'verified_at' => now()->toISOString(),
                    'executed_at' => null,
                ],
            ],
        ], $this->exportedData($request)['ownership_transfers']);
    }
}
