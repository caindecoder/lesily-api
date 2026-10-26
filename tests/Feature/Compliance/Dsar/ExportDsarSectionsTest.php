<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use Ramsey\Uuid\Uuid;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\OwnershipTransferCase;
use App\Companies\Memberships\Entities\Invitation;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Notifications\DatabaseNotification;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Entities\NotificationPreference;
use App\Compliance\Dsar\Exceptions\DsarExportArchiveFailedException;

class ExportDsarSectionsTest extends DsarTestCase
{
    protected const LOWER_ID = '00000000-0000-4000-8000-000000000001';

    protected const HIGHER_ID = 'ffffffff-ffff-4fff-bfff-ffffffffffff';

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test]
    public function when_the_archive_cannot_be_created_then_the_export_fails_without_fulfilling(): void
    {
        // Given a member's own request, and another process already holding the name the archive picks for its ZIP
        $request = $this->aSubmittedRequest($this->company, $this->aCompanyMember($this->company));
        $takenFile = $this->anArchiveWhoseNameIsAlreadyTaken();

        try {
            // When their export is built
            $this->runTheExportFor($request);
            $this->fail('An archive that cannot be created should fail the export job.');
        } catch (DsarExportArchiveFailedException) {
            // Then the attempt fails on the archive, the request stays in progress with no file, and the other process's file is left alone
            $this->assertStillInProgressWithoutExportFile($request);
            $this->assertFileExists($takenFile);
        }
    }

    #[Test]
    public function when_another_worker_takes_a_freed_temporary_name_then_the_export_still_builds(): void
    {
        // Given a member's own request, and an archive sharing its directory with another worker that takes every freed name
        $request = $this->aSubmittedRequest($this->company, $this->aCompanyMember($this->company));
        $this->anArchiveWorkingInAnEmptyDirectory($this->aFilesystemWhereAnotherWorkerTakesEveryFreedName());

        // When their export is built
        $this->runTheExportFor($request);

        // Then the export holds their data and their report
        $this->assertExportHoldsDataAndReport($request);
    }

    #[Test]
    public function when_the_member_belongs_to_two_companies_then_only_this_companys_data_is_exported(): void
    {
        // Given a member who joined another company first, under another role, with consent, an invitation, a transfer, a case, a notice, a refused lookup and an audited submission in each — beside a co-worker's consent here
        $member = User::factory()->create();
        $other = Company::factory()->create();
        $other->users()->attach($member, ['role_id' => $this->seededRole('admin')->getKey()]);
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $this->aConsentRecordedBy($member, $this->company);
        $this->aConsentRecordedBy($member, $other);
        $this->anInvitationTo($member, $this->company);
        $this->anOwnershipTransfer($this->company, $this->aCompanyMember($this->company), $member);
        OwnershipTransferCase::factory()->forCompany($this->company)->proposedOwner($member)->create();
        $this->revokeTheInvitation($this->anInvitationTo($member, $other));
        $this->anOwnershipTransfer($other, $member, $this->aCompanyMember($other));
        OwnershipTransferCase::factory()->forCompany($other)->proposedOwner($member)->closedRejected()->create();
        $this->aNotificationFor($member, NotificationType::TeamWelcome, $this->company);
        $this->aNotificationFor($member, NotificationType::OwnershipReceived, $other);
        $this->lookUpAMissingRequest($member, $this->company);
        $this->lookUpAMissingRequest($member, $other);
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
    public function when_co_workers_have_their_own_sign_ins_invitations_transfers_notices_and_access_records_then_none_of_theirs_is_exported(): void
    {
        // Given a member beside a co-worker who signed in, was refused a lookup here, was invited, is named on a case, was welcomed here, was told their password changed and switched an email off, and a transfer between two others
        $member = $this->aCompanyMember($this->company);
        $coWorker = $this->aCompanyMember($this->company);
        $this->aSignInFor($coWorker, 'iPhone', now()->addDays(90));
        $this->signInThroughTheApi($coWorker);
        $this->lookUpAMissingRequest($coWorker, $this->company);
        $this->anInvitationTo($coWorker, $this->company);
        OwnershipTransferCase::factory()->forCompany($this->company)->proposedOwner($coWorker)->create();
        $this->anOwnershipTransfer($this->company, $this->aCompanyMember($this->company), $coWorker);
        $this->aNotificationFor($coWorker, NotificationType::TeamWelcome, $this->company);
        $this->aNotificationFor($coWorker, NotificationType::PasswordChanged, null);
        NotificationPreference::factory()->mailDisabled($coWorker, NotificationType::GeneralAccountNotice)->create();
        $request = $this->aSubmittedRequest($this->company, $member);

        // When the member's export is built
        $this->runTheExportFor($request);

        // Then none of the others' sign-ins, invitations, transfers, cases, notices, preferences or access records is theirs
        $exported = $this->exportedData($request);
        $this->assertSame(
            [[], [], ['transfers' => [], 'cases' => []], ['notifications' => [], 'preferences' => []], []],
            [$exported['sign_ins'], $exported['invitations'], $exported['ownership_transfers'], $exported['notifications'], $exported['access_log']],
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
        // Given a member who never consented here, acted in nothing audited, holds no sign-in, was never invited, took part in no transfer, received no notice, switched none off and was never refused
        $member = $this->aCompanyMember($this->company);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then those sections are present and empty
        $exported = $this->exportedData($request);
        $this->assertSame(
            [[], [], [], [], ['transfers' => [], 'cases' => []], ['notifications' => [], 'preferences' => []], []],
            [$exported['consent_records'], $exported['audit_trail'], $exported['sign_ins'], $exported['invitations'], $exported['ownership_transfers'], $exported['notifications'], $exported['access_log']],
        );
    }

    #[Test]
    public function when_a_notice_concerned_their_whole_account_then_it_is_exported_without_another_companys_name(): void
    {
        // Given a member of another company told yesterday that their password changed — the notice labelled with that company — who joined here and was welcomed today
        $member = $this->aCompanyMember(Company::factory()->create());
        $this->travel(-1)->days();
        $this->aNotificationFor($member, NotificationType::PasswordChanged, null);
        $this->travel(1)->days();
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $this->aNotificationFor($member, NotificationType::TeamWelcome, $this->company);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export here is built
        $this->runTheExportFor($request);

        // Then the account-wide notice is exported beside this company's, oldest first, naming no company
        $this->assertSame(
            [['password_changed', null], ['team_welcome', $this->company->name]],
            array_map(
                fn (array $notice): array => [$notice['notification_type'], $notice['company_name']],
                $this->exportedData($request)['notifications']['notifications'],
            ),
        );
    }

    #[Test]
    public function when_the_invitation_was_typed_in_other_letter_case_then_it_is_still_exported(): void
    {
        // Given a member registered as Nimal.Perera@Example.lk whose invitation was typed nimal.perera@EXAMPLE.LK, and who accepted it
        $member = User::factory()->withEmail('Nimal.Perera@Example.lk')->create();
        $invitationId = $this->anInvitationToEmail('nimal.perera@EXAMPLE.LK', $this->company);
        $this->acceptTheInvitation($invitationId, $member);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then the invitation is still theirs
        $this->assertCount(1, $this->exportedData($request)['invitations']);
    }

    #[Test]
    public function when_they_were_invited_twice_then_each_invitation_is_exported_oldest_first(): void
    {
        // Given a member whose first invitation was revoked yesterday and whose second, sent today, they accepted
        $member = User::factory()->create();
        $this->travel(-1)->days();
        $this->revokeTheInvitation($this->anInvitationTo($member, $this->company));
        $this->travel(1)->days();
        $this->acceptTheInvitation($this->anInvitationTo($member, $this->company), $member);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then both invitations are exported, each with its own timeline, the older first
        $this->assertSame(
            [['invited', 'revoked'], ['invited', 'joined']],
            array_map(fn (array $invitation): array => array_column($invitation['timeline'], 'event'), $this->exportedData($request)['invitations']),
        );
    }

    #[Test]
    public function when_their_membership_changed_after_joining_then_only_the_invitation_rounds_are_exported(): void
    {
        // Given a member who joined through an invitation and was later made an admin
        $member = User::factory()->create();
        $invitationId = $this->anInvitationTo($member, $this->company);
        $this->acceptTheInvitation($invitationId, $member);
        $this->changeTheRole($invitationId, 'admin');
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then the invitation's timeline holds its own rounds alone
        $this->assertSame(
            ['invited', 'joined'],
            array_column($this->exportedData($request)['invitations'][0]['timeline'], 'event'),
        );
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
            ['policy_type' => 'tos', 'version' => '2026-03', 'ip_address' => '203.0.113.7', 'recorded_at' => now()->toISOString()],
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
        // Given a member who signed in on a phone today, not used yet, and — recorded after it — on a laptop a week ago, used the next day and since lapsed
        $member = $this->aCompanyMember($this->company);
        $this->aSignInFor($member, 'iPhone', now()->addDays(90));
        $this->travel(-7)->days();
        $this->aSignInFor($member, 'Chrome on Windows', now()->addDays(6))->forceFill(['last_used_at' => now()->addDay()])->save();
        $this->travel(7)->days();
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then both sign-ins are exported, oldest first
        $this->assertSame([
            ['device_name' => 'Chrome on Windows', 'signed_in_at' => now()->subDays(7)->toISOString(), 'last_used_at' => now()->subDays(6)->toISOString(), 'expires_at' => now()->subDay()->toISOString()],
            ['device_name' => 'iPhone', 'signed_in_at' => now()->toISOString(), 'last_used_at' => null, 'expires_at' => now()->addDays(90)->toISOString()],
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
        // Given a member who received the company, was the owner a rejected case expected, handed it on through support, and became new owner on a case executed the day before — recorded after the others
        $member = $this->aCompanyMember($this->company);
        $this->anOwnershipTransfer($this->company, $this->aCompanyMember($this->company), $member);
        $rejected = OwnershipTransferCase::factory()->forCompany($this->company->refresh())->closedRejected()->create();
        $this->aSupportAssistedTransfer($this->company, $member, $this->aCompanyMember($this->company));
        $this->travel(-1)->days();
        $executed = OwnershipTransferCase::factory()->forCompany($this->company)->proposedOwner($member)->closedExecuted()->create();
        $this->travel(1)->days();
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then both transfers and both cases are exported
        $this->assertSame([
            'transfers' => [
                ['transferred_at' => now()->toISOString(), 'role' => 'new_owner', 'source' => 'self_service'],
                ['transferred_at' => now()->toISOString(), 'role' => 'previous_owner', 'source' => 'admin'],
            ],
            'cases' => [
                [
                    'status' => 'closed',
                    'outcome' => 'executed',
                    'rejection_reason_code' => null,
                    'requested_context' => $executed->requested_context,
                    'opened_at' => now()->subDay()->toISOString(),
                    'verified_at' => now()->subDay()->toISOString(),
                    'executed_at' => now()->subDay()->toISOString(),
                ],
                [
                    'status' => 'closed',
                    'outcome' => 'rejected',
                    'rejection_reason_code' => 'identity_failed',
                    'requested_context' => $rejected->requested_context,
                    'opened_at' => now()->toISOString(),
                    'verified_at' => null,
                    'executed_at' => null,
                ],
            ],
        ], $this->exportedData($request)['ownership_transfers']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_notifications_section_holds_their_notifications_here_and_their_preferences(): void
    {
        // Given a member handed the company today and welcomed here two days ago — the newer notice recorded first under the lower id — who read the welcome yesterday and switched the team, then the general emails off
        $member = $this->aCompanyMember($this->company);
        $this->theNextIdIs(self::LOWER_ID);
        $this->aNotificationFor($member, NotificationType::OwnershipReceived, $this->company);
        $this->travel(-2)->days();
        $this->theNextIdIs(self::HIGHER_ID);
        $this->aNotificationFor($member, NotificationType::TeamWelcome, $this->company);
        $this->travel(1)->days();
        DatabaseNotification::query()->findOrFail(self::HIGHER_ID)->markAsRead();
        $this->travel(1)->days();
        Str::createUuidsNormally();
        NotificationPreference::factory()->mailDisabled($member, NotificationType::TeamWelcome)->create();
        NotificationPreference::factory()->mailDisabled($member, NotificationType::GeneralAccountNotice)->create();
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then both notices are exported oldest first, beside the preferences they hold in type order
        $this->assertSame([
            'notifications' => [
                ['notification_type' => 'team_welcome', 'company_name' => $this->company->name, 'sent_at' => now()->subDays(2)->toISOString(), 'read_at' => now()->subDay()->toISOString()],
                ['notification_type' => 'ownership_received', 'company_name' => $this->company->name, 'sent_at' => now()->toISOString(), 'read_at' => null],
            ],
            'preferences' => [
                ['notification_type' => 'general_account_notice', 'channel' => 'mail', 'enabled' => false, 'changed_at' => now()->toISOString()],
                ['notification_type' => 'team_welcome', 'channel' => 'mail', 'enabled' => false, 'changed_at' => now()->toISOString()],
            ],
        ], $this->exportedData($request)['notifications']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_access_section_holds_their_sign_ins_and_refusals_oldest_first(): void
    {
        // Given a member refused twice here a minute apart, and — recorded after it — who mistyped their password an hour before, then signed in
        $member = $this->aCompanyMember($this->company);
        $this->lookUpAMissingRequest($member, $this->company);
        $this->travel(1)->minutes();
        $this->lookUpAMissingRequest($member, $this->company);
        $this->travel(-61)->minutes();
        $this->signInWithAWrongPassword($member);
        $this->signInThroughTheApi($member);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then the failed and the successful sign-in and the counted refusal are exported, oldest first
        $this->assertSame([
            ['access_event' => 'login_failed', 'access_reason' => 'wrong_password', 'http_method' => 'POST', 'route' => 'api/v1/auth/login', 'ip_address' => '127.0.0.1', 'user_agent' => 'Symfony', 'occurrences' => 1, 'first_seen_at' => now()->toISOString(), 'last_seen_at' => now()->toISOString()],
            ['access_event' => 'login_succeeded', 'access_reason' => null, 'http_method' => 'POST', 'route' => 'api/v1/auth/login', 'ip_address' => '127.0.0.1', 'user_agent' => 'Symfony', 'occurrences' => 1, 'first_seen_at' => now()->toISOString(), 'last_seen_at' => now()->toISOString()],
            ['access_event' => 'not_found', 'access_reason' => null, 'http_method' => 'GET', 'route' => 'api/v1/dsar-requests/{requestId}', 'ip_address' => '127.0.0.1', 'user_agent' => 'Symfony', 'occurrences' => 2, 'first_seen_at' => now()->addHour()->toISOString(), 'last_seen_at' => now()->addMinutes(61)->toISOString()],
        ], $this->exportedData($request)['access_log']);
    }

    /**
     * The next generated UUID is $id; those after it are random again.
     */
    protected function theNextIdIs(string $id): void
    {
        Str::createUuidsUsingSequence([Uuid::fromString($id)]);
    }
}
