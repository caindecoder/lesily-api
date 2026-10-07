<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\Entities\OwnershipTransferCase;
use App\Companies\Memberships\Entities\Invitation;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
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

        // When their export is built
        $attempt = fn () => $this->runTheExportFor($request);

        // Then the attempt fails on the report, and the request stays in progress with no file
        $this->assertThrows($attempt, DsarReportRenderingFailedException::class);
        $this->assertStillInProgressWithoutExportFile($request);
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
        // Given a member who never consented here, acted in nothing audited, holds no sign-in, was never invited, took part in no transfer, received no notice and switched none off
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
        ]);
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
            'a role code' => ['role', 'hr_officer', 'HR Officer (hr_officer)'],
            'a code with no explanation yet' => ['action', 'a_new_action', 'a_new_action'],
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
        // Given a member's submitted request
        $request = $this->aSubmittedRequest($this->company, $this->member);

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
