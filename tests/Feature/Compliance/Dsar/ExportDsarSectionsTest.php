<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use Ramsey\Uuid\Uuid;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Companies\Entities\OwnershipTransferCase;
use App\Companies\Memberships\Entities\Invitation;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Notifications\DatabaseNotification;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Entities\NotificationPreference;
use App\Compliance\Dsar\Exceptions\DsarExportArchiveFailedException;

class ExportDsarSectionsTest extends DsarTestCase
{
    protected const LOWER_ID = '00000000-0000-4000-8000-000000000001';

    protected const HIGHER_ID = 'ffffffff-ffff-4fff-bfff-ffffffffffff';

    #[Test]
    public function when_the_archive_cannot_be_created_then_the_export_fails_without_fulfilling(): void
    {
        // Given a member's own request, and another process already holding the name the archive picks for its ZIP
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->aCompanyMember($this->company));
        $takenFile = $this->anArchiveWhoseNameIsAlreadyTaken();

        try {
            // When their export is built
            $this->runTheExportFor($dsarRequest);
            $this->fail('An archive that cannot be created should fail the export job.');
        } catch (DsarExportArchiveFailedException) {
            // Then the attempt fails on the archive, the request stays in progress with no file, and the other process's file is left alone
            $this->assertStillInProgressWithoutExportFile($dsarRequest);
            $this->assertFileExists($takenFile);
        }
    }

    #[Test]
    public function when_another_worker_takes_a_freed_temporary_name_then_the_export_still_builds(): void
    {
        // Given a member's own request, and an archive sharing its directory with another worker that takes every freed name
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->aCompanyMember($this->company));
        $this->anArchiveWorkingInAnEmptyDirectory($this->aFilesystemWhereAnotherWorkerTakesEveryFreedName());

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then the export holds their data and their report
        $this->assertExportHoldsDataAndReport($dsarRequest);
    }

    #[Test]
    public function when_the_member_belongs_to_two_companies_then_only_this_companys_data_is_exported(): void
    {
        // Given a member who joined another company first, under another role, with consent, an invitation, a transfer, a case, a notice, a refused lookup, a role change by an admin and an audited submission in each — beside a co-worker's consent here
        $member = User::factory()->create();
        $otherCompany = Company::factory()->create();
        $otherCompany->users()->attach($member, ['role_id' => $this->seededRole('admin')->getKey()]);
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $this->anAdminChangesTheRoleOf($member, $this->company);
        $this->anAdminChangesTheRoleOf($member, $otherCompany);
        $this->aConsentRecordedBy($member, $this->company);
        $this->aConsentRecordedBy($member, $otherCompany);
        $this->anInvitationTo($member, $this->company);
        $this->anOwnershipTransfer($this->company, $this->owner, $member);
        OwnershipTransferCase::factory()->forCompany($this->company)->proposedOwner($member)->create();
        $this->revokeTheInvitation($this->anInvitationTo($member, $otherCompany));
        $this->anOwnershipTransfer($otherCompany, $member, $this->aCompanyMember($otherCompany));
        OwnershipTransferCase::factory()->forCompany($otherCompany)->proposedOwner($member)->closedRejected()->create();
        $this->aNotificationFor($member, NotificationType::TeamWelcome, $this->company);
        $this->aNotificationFor($member, NotificationType::OwnershipReceived, $otherCompany);
        $this->lookUpAMissingRequest($member, $this->company);
        $this->lookUpAMissingRequest($member, $otherCompany);
        $this->aCompanyMember($this->company);
        $this->submitDsarRequest($member, $otherCompany)->assertCreated();
        $this->idempotencyKey = (string) Str::uuid();
        $dsarRequest = $this->requestFrom($this->submitDsarRequest($member, $this->company));

        // When their export here is built
        $this->runTheExportFor($dsarRequest);

        // Then every section holds this company's data alone
        $this->assertExportScopedTo($this->exportedData($dsarRequest), $this->company, $member);
    }

    #[Test]
    public function when_co_workers_have_their_own_sign_ins_invitations_transfers_notices_and_access_records_then_none_of_theirs_is_exported(): void
    {
        // Given a member beside a co-worker who signed in, was refused a lookup here, was invited, is named on a case, was welcomed here, was told their password changed and switched an email off, and the owner handing the company to the co-worker
        $subject = $this->aCompanyMember($this->company);
        $member = $this->aCompanyMember($this->company);
        $this->aSignInFor($member, 'iPhone', now()->addDays(90));
        $this->signInThroughTheApi($member);
        $this->lookUpAMissingRequest($member, $this->company);
        $this->anInvitationTo($member, $this->company);
        OwnershipTransferCase::factory()->forCompany($this->company)->proposedOwner($member)->create();
        $this->anOwnershipTransfer($this->company, $this->owner, $member);
        $this->aNotificationFor($member, NotificationType::TeamWelcome, $this->company);
        $this->aNotificationFor($member, NotificationType::PasswordChanged, null);
        NotificationPreference::factory()->mailDisabled($member, NotificationType::GeneralAccountNotice)->create();
        $dsarRequest = $this->aSubmittedRequest($this->company, $subject);

        // When the member's export is built
        $this->runTheExportFor($dsarRequest);

        // Then none of the others' sign-ins, invitations, transfers, cases, notices, preferences or access records is theirs
        $exported = $this->exportedData($dsarRequest);
        $this->assertSame(
            [[], [], ['transfers' => [], 'cases' => []], ['notifications' => [], 'preferences' => []], []],
            [$exported['sign_ins'], $exported['invitations'], $exported['ownership_transfers'], $exported['notifications'], $exported['access_log']],
        );
    }

    #[Test]
    public function when_another_member_is_acted_on_then_that_row_is_not_in_their_export(): void
    {
        // Given an admin who changed a co-worker's role, beside a member who asked for their data
        $member = $this->aCompanyMember($this->company);
        $this->anAdminChangesTheRoleOf($this->aCompanyMember($this->company), $this->company);
        $dsarRequest = $this->requestFrom($this->submitDsarRequest($member, $this->company));

        // When the member's export is built
        $this->runTheExportFor($dsarRequest);

        // Then only the member's own submission is in their audit section
        $this->assertSame(['dsar_requested'], array_column($this->auditSectionOf($dsarRequest), 'action'));
    }

    #[Test]
    public function when_a_row_about_another_kind_of_record_carries_their_id_then_it_is_not_in_their_export(): void
    {
        // Given a co-worker's audit row about an invoice whose id matches the member's, beside the member's own request
        $member = $this->aCompanyMember($this->company);
        AuditEntry::factory()->forCompany($this->company)->concerning($member->getKey())->create();
        $dsarRequest = $this->requestFrom($this->submitDsarRequest($member, $this->company));

        // When the member's export is built
        $this->runTheExportFor($dsarRequest);

        // Then only the member's own submission is in their audit section
        $this->assertSame(['dsar_requested'], array_column($this->auditSectionOf($dsarRequest), 'action'));
    }

    #[Test]
    public function when_someone_else_acted_on_them_then_the_row_holds_no_ip_or_device(): void
    {
        // Given a member whose role an admin changed, who then asked for their data
        $member = $this->aCompanyMember($this->company);
        $this->anAdminChangesTheRoleOf($member, $this->company);
        $dsarRequest = $this->requestFrom($this->submitDsarRequest($member, $this->company));

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then the role change is theirs to see, without the admin's network address or device
        $roleChange = collect($this->auditSectionOf($dsarRequest))->sole('action', 'role_changed');
        $this->assertSame([null, null], [$roleChange['ip_address'], $roleChange['user_agent']]);
    }

    #[Test]
    public function when_they_changed_their_own_record_then_the_row_appears_once_with_its_ip_and_device(): void
    {
        // Given the owner of another company, who set their emergency contact there, then asked for their data
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        $this->theySetTheirEmergencyContact($owner, $company);
        $dsarRequest = $this->requestFrom($this->submitDsarRequest($owner, $company));

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then the change is one row, from their own network address and device
        $contactRowCollection = collect($this->auditSectionOf($dsarRequest))->where('action', 'emergency_contact_updated');
        $this->assertCount(1, $contactRowCollection);
        $contactRow = $contactRowCollection->sole();
        $this->assertSame([self::MEMBER_IP, self::MEMBER_DEVICE], [$contactRow['ip_address'], $contactRow['user_agent']]);
    }

    #[Test]
    public function when_a_change_was_encrypted_then_the_export_holds_its_plain_values(): void
    {
        // Given the owner of another company, who set their emergency contact there, stored encrypted in its audit row, then asked for their data
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        $this->theySetTheirEmergencyContact($owner, $company);
        $dsarRequest = $this->requestFrom($this->submitDsarRequest($owner, $company));

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then the stored change is encrypted, yet the export shows each value in plain words, with no ciphertext and no marker anywhere
        $this->assertTrue(AuditEntry::query()->where('action', 'emergency_contact_updated')->where('actor_user_id', $owner->getKey())->sole()->metadata['changes']['name']['encrypted'] ?? false);
        /** @var list<array<string, mixed>> $changes */
        $changes = collect($this->auditSectionOf($dsarRequest))->sole('action', 'emergency_contact_updated')['changes'];
        $this->assertSame([
            ['field' => 'email', 'from' => null, 'to' => self::CONTACT['email']],
            ['field' => 'name', 'from' => null, 'to' => self::CONTACT['name']],
            ['field' => 'phone', 'from' => null, 'to' => self::CONTACT['phone']],
            ['field' => 'relationship', 'from' => null, 'to' => self::CONTACT['relationship']],
        ], collect($changes)->sortBy('field')->values()->all());
        $this->assertDoesNotMatchRegularExpression('/"encrypted"|eyJpdiI6/', $this->exportedJsonOf($dsarRequest));
    }

    #[Test]
    public function when_a_row_records_no_changes_then_its_changes_are_empty(): void
    {
        // Given a member whose only audited action is asking for their data
        $dsarRequest = $this->requestFrom($this->submitDsarRequest($this->aCompanyMember($this->company), $this->company));

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then that row lists no changes
        $this->assertSame([], collect($this->auditSectionOf($dsarRequest))->sole('action', 'dsar_requested')['changes']);
    }

    #[Test]
    public function when_the_export_is_built_then_no_secret_is_exported(): void
    {
        // Given a member with a password, a remember token, two-factor sign-in, a sign-in token, an invitation and a verified-then-rejected case
        $member = User::factory()->withTwoFactor()->create();
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $personalAccessToken = $this->aSignInFor($member, 'iPhone', now()->addDays(90));
        $invitationId = $this->anInvitationTo($member, $this->company);
        $ownershipTransferCase = OwnershipTransferCase::factory()->forCompany($this->company)->proposedOwner($member)->verified()->closedRejected()->create();
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then none of their secrets, token hashes, case notes or prose reasons appears anywhere in it
        $this->assertNoSecretOf($member, $this->exportedData($dsarRequest), [
            $personalAccessToken->token,
            Invitation::query()->findOrFail($invitationId)->token_hash,
            $ownershipTransferCase->verification_notes,
            $ownershipTransferCase->rejection_reason,
        ]);
    }

    #[Test]
    public function when_the_export_is_built_then_no_temporary_file_outlives_it(): void
    {
        // Given a member's own request and an empty working directory for the archive
        $member = $this->aCompanyMember($this->company);
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);
        $workDirectory = $this->anArchiveWorkingInAnEmptyDirectory();

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then the ZIP reached the dsar disk and no copy of their data is left behind
        $this->assertExportBuiltWithoutLeftovers($dsarRequest, $workDirectory);
    }

    #[Test]
    public function when_the_export_is_built_then_its_data_is_indented_for_reading(): void
    {
        // Given
        $dsarRequest = $this->aSubmittedRequest($this->company, $this->aCompanyMember($this->company));

        // When
        $this->runTheExportFor($dsarRequest);

        // Then
        $this->assertStringStartsWith("{\n    \"profile\": {\n        \"id\": ", $this->exportedJsonOf($dsarRequest));
    }

    #[Test]
    public function when_a_section_has_no_entries_then_it_is_exported_empty(): void
    {
        // Given a member who never consented here, acted in nothing audited, holds no sign-in, was never invited, took part in no transfer, received no notice, switched none off and was never refused
        $member = $this->aMemberWhoNeverConsented($this->company);
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then those sections are present and empty
        $exported = $this->exportedData($dsarRequest);
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
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export here is built
        $this->runTheExportFor($dsarRequest);

        // Then the account-wide notice is exported beside this company's, oldest first, naming no company
        $this->assertSame(
            [['password_changed', null], ['team_welcome', $this->company->name]],
            array_map(
                fn (array $notice): array => [$notice['notification_type'], $notice['company_name']],
                $this->exportedData($dsarRequest)['notifications']['notifications'],
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
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then the invitation is still theirs
        $this->assertCount(1, $this->exportedData($dsarRequest)['invitations']);
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
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then both invitations are exported, each with its own timeline, the older first
        $this->assertSame(
            [['invited', 'revoked'], ['invited', 'joined']],
            array_map(fn (array $invitation): array => array_column($invitation['timeline'], 'event'), $this->exportedData($dsarRequest)['invitations']),
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
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then the invitation's timeline holds its own rounds alone
        $this->assertSame(
            ['invited', 'joined'],
            array_column($this->exportedData($dsarRequest)['invitations'][0]['timeline'], 'event'),
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
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then every round is exported in order
        $this->assertSame([[
            'timeline' => [
                ['event' => 'invited', 'role' => 'employee', 'at' => now()->toISOString()],
                ['event' => 'expired', 'role' => null, 'at' => now()->toISOString()],
                ['event' => 'invited', 'role' => 'admin', 'at' => now()->toISOString()],
                ['event' => 'resent', 'role' => null, 'at' => now()->toISOString()],
                ['event' => 'revoked', 'role' => null, 'at' => now()->toISOString()],
            ],
        ]], $this->exportedData($dsarRequest)['invitations']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_audit_section_holds_the_entries_they_acted_in_and_about_them(): void
    {
        // Given a member who asked for their data, and whose role an admin had changed the day before — recorded after it — beside another member's audited submission
        $member = $this->aCompanyMember($this->company);
        $dsarRequest = $this->requestFrom($this->submitDsarRequest($member, $this->company));
        $this->travelTo('2026-09-30 09:00:00');
        $this->anAdminChangesTheRoleOf($member, $this->company);
        $this->travelTo('2026-10-01 09:00:00');
        $this->idempotencyKey = (string) Str::uuid();
        $this->submitDsarRequest($this->aCompanyMember($this->company), $this->company)->assertCreated();

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then the entry about them and the entry they acted in are exported, oldest first, each with its changes
        $roleChangeAuditEntry = AuditEntry::query()->where('action', 'role_changed')->sole();
        $submissionAuditEntry = AuditEntry::query()->where('actor_user_id', $member->getKey())->sole();
        $this->assertSame([
            [
                'recorded_at' => $roleChangeAuditEntry->recorded_at->toISOString(),
                'action' => 'role_changed',
                'description' => $roleChangeAuditEntry->description,
                'subject_type' => 'user',
                'subject_id' => $member->getKey(),
                'ip_address' => null,
                'user_agent' => null,
                'changes' => [['field' => 'role', 'from' => 'employee', 'to' => 'hr_officer']],
            ],
            [
                'recorded_at' => $submissionAuditEntry->recorded_at->toISOString(),
                'action' => 'dsar_requested',
                'description' => $submissionAuditEntry->description,
                'subject_type' => 'dsar_request',
                'subject_id' => $dsarRequest->getKey(),
                'ip_address' => $submissionAuditEntry->ip_address,
                'user_agent' => $submissionAuditEntry->user_agent,
                'changes' => [],
            ],
        ], $this->auditSectionOf($dsarRequest));
    }

    #[Test]
    public function when_the_export_is_built_then_the_consent_section_holds_their_consent_records(): void
    {
        // Given a member who agreed to the terms and the privacy policy here, before both policies moved on
        config(['lesily.policies' => ['tos' => '2026-03', 'privacy' => '2026-04']]);
        $member = $this->aCompanyMember($this->company);
        config(['lesily.policies' => ['tos' => 'v2.0', 'privacy' => 'v2.0']]);
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then
        $this->assertSame([
            ['policy_type' => 'tos', 'version' => '2026-03', 'ip_address' => '198.51.100.4', 'recorded_at' => now()->toISOString()],
            ['policy_type' => 'privacy', 'version' => '2026-04', 'ip_address' => '198.51.100.4', 'recorded_at' => now()->toISOString()],
        ], $this->exportedData($dsarRequest)['consent_records']);
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
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

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
        ], $this->exportedData($dsarRequest)['membership']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_profile_section_holds_the_persons_profile(): void
    {
        // Given a member with two-factor sign-in, a linked Google account, an unverified email, who last signed in yesterday
        $member = User::factory()->unverified()->withTwoFactor()->linkedToGoogle('109876543210')
            ->lastLoggedAt('2026-09-30 18:00:00')->create();
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

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
            'google_account_id' => '109876543210',
        ], $this->exportedData($dsarRequest)['profile']);
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
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then both sign-ins are exported, oldest first
        $this->assertSame([
            ['device_name' => 'Chrome on Windows', 'signed_in_at' => now()->subDays(7)->toISOString(), 'last_used_at' => now()->subDays(6)->toISOString(), 'expires_at' => now()->subDay()->toISOString()],
            ['device_name' => 'iPhone', 'signed_in_at' => now()->toISOString(), 'last_used_at' => null, 'expires_at' => now()->addDays(90)->toISOString()],
        ], $this->exportedData($dsarRequest)['sign_ins']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_invitations_section_holds_the_invitation_that_brought_them_in(): void
    {
        // Given a member who joined through an invitation
        $member = User::factory()->create();
        $invitationId = $this->anInvitationTo($member, $this->company);
        $this->acceptTheInvitation($invitationId, $member);
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then the invitation is exported with its timeline
        $this->assertSame([[
            'timeline' => [
                ['event' => 'invited', 'role' => 'employee', 'at' => now()->toISOString()],
                ['event' => 'joined', 'role' => 'employee', 'at' => now()->toISOString()],
            ],
        ]], $this->exportedData($dsarRequest)['invitations']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_ownership_section_holds_the_transfers_and_cases_they_took_part_in(): void
    {
        // Given a member who received the company, was the owner a rejected case expected, handed it on through support, and became new owner on a case executed the day before — recorded after the others
        $member = $this->aCompanyMember($this->company);
        $this->anOwnershipTransfer($this->company, $this->owner, $member);
        $rejectedOwnershipTransferCase = OwnershipTransferCase::factory()->forCompany($this->company->refresh())->closedRejected()->create();
        $this->aSupportAssistedTransfer($this->company, $member, $this->aCompanyMember($this->company));
        $this->travel(-1)->days();
        $executedOwnershipTransferCase = OwnershipTransferCase::factory()->forCompany($this->company)->proposedOwner($member)->closedExecuted()->create();
        $this->travel(1)->days();
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

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
                    'requested_context' => $executedOwnershipTransferCase->requested_context,
                    'opened_at' => now()->subDay()->toISOString(),
                    'verified_at' => now()->subDay()->toISOString(),
                    'executed_at' => now()->subDay()->toISOString(),
                ],
                [
                    'status' => 'closed',
                    'outcome' => 'rejected',
                    'rejection_reason_code' => 'identity_failed',
                    'requested_context' => $rejectedOwnershipTransferCase->requested_context,
                    'opened_at' => now()->toISOString(),
                    'verified_at' => null,
                    'executed_at' => null,
                ],
            ],
        ], $this->exportedData($dsarRequest)['ownership_transfers']);
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
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

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
        ], $this->exportedData($dsarRequest)['notifications']);
    }

    #[Test]
    public function when_a_member_of_two_companies_is_exported_then_the_access_section_holds_only_this_company_copy(): void
    {
        // Given a member of this company and of another, refused an hour ago at a door no company owns,
        // who then signed in once — a copy logged for each company
        $member = $this->aCompanyMember($this->company);
        $this->attachMember(Company::factory()->create(), $member, $this->seededRole('employee'));
        AccessLogEntry::factory()->byUser($member)->ofEvent(AccessEvent::Forbidden)->seenAt(now()->subHour())->create();
        $this->signInThroughTheApi($member);
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export for this company is built
        $this->runTheExportFor($dsarRequest);

        // Then the section holds the no-company refusal and this company's copy of the sign-in, never the other company's
        $this->assertSame(['forbidden', 'login_succeeded'], array_column($this->exportedData($dsarRequest)['access_log'], 'access_event'));
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
        $dsarRequest = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($dsarRequest);

        // Then the failed and the successful sign-in and the counted refusal are exported, oldest first
        $this->assertSame([
            ['access_event' => 'login_failed', 'access_reason' => 'wrong_password', 'http_method' => 'POST', 'route' => 'api/v1/auth/login', 'ip_address' => '127.0.0.1', 'user_agent' => 'Symfony', 'occurrences' => 1, 'first_seen_at' => now()->toISOString(), 'last_seen_at' => now()->toISOString()],
            ['access_event' => 'login_succeeded', 'access_reason' => null, 'http_method' => 'POST', 'route' => 'api/v1/auth/login', 'ip_address' => '127.0.0.1', 'user_agent' => 'Symfony', 'occurrences' => 1, 'first_seen_at' => now()->toISOString(), 'last_seen_at' => now()->toISOString()],
            ['access_event' => 'not_found', 'access_reason' => null, 'http_method' => 'GET', 'route' => 'api/v1/dsar-requests/{requestId}', 'ip_address' => '127.0.0.1', 'user_agent' => 'Symfony', 'occurrences' => 2, 'first_seen_at' => now()->addHour()->toISOString(), 'last_seen_at' => now()->addMinutes(61)->toISOString()],
        ], $this->exportedData($dsarRequest)['access_log']);
    }

    /**
     * The next generated UUID is $id; those after it are random again.
     */
    protected function theNextIdIs(string $id): void
    {
        Str::createUuidsUsingSequence([Uuid::fromString($id)]);
    }
}
