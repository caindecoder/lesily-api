<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Companies\Entities\OwnershipTransferCase;
use App\Companies\Memberships\Entities\Invitation;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Entities\NotificationPreference;
use App\Compliance\Dsar\Exceptions\DsarReportRenderingFailedException;

class ExportDsarReportTest extends DsarTestCase
{
    protected Company $company;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->member = $this->aCompanyMember($this->company);
    }

    #[Test]
    public function when_the_report_cannot_be_rendered_then_the_export_fails_without_fulfilling(): void
    {
        // Given a member's submitted request, and a renderer that cannot produce the report
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->aReportRendererThatFails();

        try {
            // When their export is built
            $this->runTheExportFor($request);
            $this->fail('A report that cannot be rendered should fail the export job.');
        } catch (DsarReportRenderingFailedException) {
            // Then the attempt fails on the report, and the request stays in progress with no file
            $this->assertStillInProgressWithoutExportFile($request);
        }
    }

    #[Test]
    public function when_the_export_is_built_then_no_secret_appears_in_the_report(): void
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

        // Then none of their secrets, token hashes, case notes or prose reasons appears in the report
        $this->assertReportHoldsNoSecretOf($member, [
            $signIn->token,
            Invitation::query()->findOrFail($invitationId)->token_hash,
            $case->verification_notes,
            $case->rejection_reason,
        ]);
    }

    #[Test]
    public function when_a_section_has_no_entries_then_the_report_says_none_recorded(): void
    {
        // Given a member who never consented here, acted in nothing audited, holds no sign-in, was never invited, took part in no transfer, received no notice, switched none off and was never refused
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then every empty list in the report reads "None recorded" under its own heading
        $this->assertReportShowsInOrder([
            'Notifications and preferences',
            'Consent records', 'None recorded',
            'Actions you took', 'None recorded',
            'Sign-ins', 'None recorded',
            'Invitations', 'None recorded',
            'Ownership transfers', 'Transfers', 'None recorded', 'Cases', 'None recorded',
            'Notifications and preferences', 'Notifications', 'None recorded', 'Preferences', 'None recorded',
            'Account access history', 'None recorded',
        ]);
        $this->assertSame(9, substr_count($this->reportRenderer->html, 'None recorded'));
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function provideValuesAndTheirReadableForms(): array
    {
        return [
            'a time' => ['recorded_at', '2026-09-15T14:30:00.000000Z', '15 Sep 2026, 14:30 UTC'],
            'a yes' => ['two_factor_enabled', true, 'Yes'],
            'a no' => ['two_factor_enabled', false, 'No'],
            'nothing' => ['last_logged_at', null, '—'],
            'an action code' => ['action', 'dsar_withdrawn', 'Withdrew a data request (dsar_withdrawn)'],
            'an expired download action' => ['action', 'dsar_download_expired', "A data export's download window closed (dsar_download_expired)"],
            'a missed deadline action' => ['action', 'dsar_deadline_missed', 'A data request missed its 30-day deadline (dsar_deadline_missed)'],
            'a download action' => ['action', 'dsar_downloaded', 'Downloaded a copy of personal data (dsar_downloaded)'],
            "an admin's view action" => ['action', 'dsar_admin_viewed', 'Viewed a data request (dsar_admin_viewed)'],
            "an admin's queue view action" => ['action', 'dsar_queue_viewed', 'Viewed the data requests (dsar_queue_viewed)'],
            'the data request queue' => ['subject_type', 'dsar_request_queue', 'The data requests (dsar_request_queue)'],
            'a role code' => ['role', 'hr_officer', 'HR Officer (hr_officer)'],
            'a ready data export notice' => ['notification_type', 'dsar_export_ready', 'Data export ready (dsar_export_ready)'],
            'a failed data export notice' => ['notification_type', 'dsar_export_failed', 'Data export failed (dsar_export_failed)'],
            'a withdrawn data request notice' => ['notification_type', 'dsar_request_withdrawn', 'Data request withdrawn (dsar_request_withdrawn)'],
            'a missed data request deadline notice' => ['notification_type', 'dsar_deadline_missed', 'Data request deadline missed (dsar_deadline_missed)'],
            'a code with no explanation yet' => ['action', 'a_new_action', 'a_new_action'],
            'a role code outside a role field' => ['device_name', 'employee', 'employee'],
        ];
    }

    #[Test, DataProvider('provideValuesAndTheirReadableForms')]
    public function when_a_value_is_a_date_flag_empty_or_code_then_the_report_writes_it_readably(string $field, mixed $value, string $readable): void
    {
        // Given an export whose section holds the value
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->withSections($this->aSectionHolding('profile', [$field => $value]));

        // When their export is built
        $this->runTheExportFor($request);

        // Then the report writes it for a person to read
        $this->assertReportCell($readable);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAuditCodes(): array
    {
        return [
            ...collect(AuditAction::cases())->mapWithKeys(fn (AuditAction $action): array => ["action {$action->value}" => ['action', $action->value]])->all(),
            ...collect(AuditSubjectType::cases())->mapWithKeys(fn (AuditSubjectType $type): array => ["subject type {$type->value}" => ['subject_type', $type->value]])->all(),
        ];
    }

    #[Test, DataProvider('provideAuditCodes')]
    public function when_any_audit_code_is_exported_then_the_report_has_words_for_it(string $field, string $code): void
    {
        // Given an export whose section holds the audit code
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->withSections($this->aSectionHolding('profile', [$field => $code]));

        // When their export is built
        $this->runTheExportFor($request);

        // Then the report explains the code in words
        $this->assertReportExplains($field, $code);
    }

    #[Test]
    public function when_a_section_holds_one_entry_then_the_report_lists_each_field_label_before_its_value(): void
    {
        // Given an export whose profile holds a name and a two-step sign-in flag
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->withSections($this->aSectionHolding('profile', ['name' => 'Nimal Perera', 'two_factor_enabled' => true]));

        // When their export is built
        $this->runTheExportFor($request);

        // Then each field reads as its label, then its value
        $this->assertReportShowsInOrder(['Name', 'Nimal Perera', 'Two-step sign-in turned on', 'Yes']);
    }

    #[Test]
    public function when_a_section_lists_entries_then_the_report_tables_them_under_column_headings(): void
    {
        // Given an export whose section lists two entries
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->withSections($this->aSectionHolding('audit_trail', [
            ['recorded_at' => '2026-09-15T14:30:00.000000Z', 'action' => 'dsar_requested'],
            ['recorded_at' => '2026-09-16T08:05:00.000000Z', 'action' => 'dsar_withdrawn'],
        ]));

        // When their export is built
        $this->runTheExportFor($request);

        // Then the entries are rows under labelled column headings, each value readable, in order
        $this->assertReportShowsInOrder([
            'When', 'What you did',
            '15 Sep 2026, 14:30 UTC', 'Asked for a copy of personal data (dsar_requested)',
            '16 Sep 2026, 08:05 UTC', 'Withdrew a data request (dsar_withdrawn)',
        ]);
    }

    #[Test]
    public function when_each_entry_holds_a_list_then_the_report_tables_it_under_its_label(): void
    {
        // Given an export whose section lists two invitations, each with its own history
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->withSections($this->aSectionHolding('invitations', [
            ['timeline' => [['event' => 'invited', 'at' => '2026-09-15T14:30:00.000000Z']]],
            ['timeline' => [['event' => 'joined', 'at' => '2026-09-16T08:05:00.000000Z']]],
        ]));

        // When their export is built
        $this->runTheExportFor($request);

        // Then each entry's list is its own labelled table, in order
        $this->assertReportShowsInOrder([
            'Invitation history', 'What happened', 'Invited (invited)', '15 Sep 2026, 14:30 UTC',
            'Invitation history', 'What happened', 'Joined (joined)', '16 Sep 2026, 08:05 UTC',
        ]);
    }

    #[Test]
    public function when_any_section_exports_a_field_then_the_report_labels_it(): void
    {
        // Given a member with an entry in every section
        $request = $this->aRequestFromAMemberWithAnEntryInEverySection();

        // When their export is built
        $this->runTheExportFor($request);

        // Then every field the export holds has a label in words, and no label is missing from the report
        $this->assertEveryFieldLabelled($this->exportedData($request));
    }

    #[Test]
    public function when_the_export_is_built_then_the_zip_holds_the_report_beside_the_data(): void
    {
        // Given a member's submitted request
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then the ZIP holds data.json and the rendered report.pdf
        $this->assertExportHoldsDataAndReport($request);
    }

    #[Test]
    public function when_the_export_is_built_then_the_report_opens_with_the_cover_and_reading_guide(): void
    {
        // Given a member's request, submitted two hours before its export is built
        $request = $this->aSubmittedRequest($this->company, $this->member);
        $this->travel(2)->hours();

        // When their export is built
        $this->runTheExportFor($request);

        // Then the report opens with who and what it is for, and how to read it
        $this->assertReportCover($request, $this->member, $this->company);
    }

    #[Test]
    public function when_the_export_is_built_then_the_report_holds_every_section_titled_in_order(): void
    {
        // Given a member's submitted request
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then the contents list names every section, and each follows under its title, in the same order
        $titles = ['Your profile', 'Your membership', 'Consent records', 'Actions you took', 'Sign-ins', 'Invitations', 'Ownership transfers', 'Notifications and preferences'];
        $this->assertReportShowsInOrder(['Contents', ...$titles, ...$titles]);
    }

    #[Test]
    public function when_a_subject_who_downloaded_an_export_asks_again_then_the_report_names_the_download(): void
    {
        // Given a member who downloaded their ready export, then asked for their data again
        $first = $this->aReadyExportFor($this->company, $this->member);
        $this->downloadDsarExport($this->member, $this->company, $first->getKey());
        $request = $this->aSubmittedRequest($this->company, $this->member);

        // When their new export is built
        $this->runTheExportFor($request);

        // Then the report names the download in words
        $this->assertReportCell('Downloaded a copy of personal data (dsar_downloaded)');
    }

    /**
     * $code has words in lang/en/dsar.php, and the report shows them with the
     * code after them.
     */
    protected function assertReportExplains(string $field, string $code): void
    {
        $key = "dsar.codes.{$field}.{$code}";
        $this->assertTrue(Lang::has($key), "No words for {$key}");
        $this->assertReportCell(__($key)." ({$code})");
    }

    /**
     * A member who joined through an invitation, consented, signed in, was
     * handed the company and named on a rejected case, received a notice,
     * switched an email off — and then submitted their request themselves.
     */
    protected function aRequestFromAMemberWithAnEntryInEverySection(): DsarRequestRecord
    {
        $member = User::factory()->create();
        $this->acceptTheInvitation($this->anInvitationTo($member, $this->company), $member);
        $this->aConsentRecordedBy($member, $this->company);
        $this->aSignInFor($member, 'iPhone', now()->addDays(90));
        $this->anOwnershipTransfer($this->company, $this->aCompanyMember($this->company), $member);
        OwnershipTransferCase::factory()->forCompany($this->company->refresh())->proposedOwner($member)->closedRejected()->create();
        $this->aNotificationFor($member, NotificationType::TeamWelcome, $this->company);
        NotificationPreference::factory()->mailDisabled($member, NotificationType::GeneralAccountNotice)->create();

        return $this->requestFrom($this->submitDsarRequest($member, $this->company));
    }
}
