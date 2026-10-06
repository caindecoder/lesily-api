<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
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
        // Given a member who joined another company first, under another role, with consent and an audited submission in each — beside a co-worker's consent here
        $member = User::factory()->create();
        $other = Company::factory()->create();
        $other->users()->attach($member, ['role_id' => $this->seededRole('admin')->getKey()]);
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $this->aConsentRecordedBy($member, $this->company);
        $this->aConsentRecordedBy($member, $other);
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
    public function when_the_export_is_built_then_no_secret_is_exported(): void
    {
        // Given a member with a password, a remember token and two-factor sign-in
        $member = User::factory()->withTwoFactor()->create();
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then none of their secrets appears anywhere in it
        $this->assertNoSecretOf($member, $this->exportedData($request));
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
}
