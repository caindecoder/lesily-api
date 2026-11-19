<?php

namespace Tests\Feature\Companies;

use Illuminate\Support\Str;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Companies\Entities\CompanyDataProtectionOfficer;
use App\Companies\Events\CompanyDataProtectionOfficerSet;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * UC-1-146 — the owner or an admin records the company's data protection officer:
 * PUT /api/v1/companies/{companyId}/data-protection-officer.
 */
class SetDataProtectionOfficerTest extends CompaniesTestCase
{
    use FreeTierCompany;

    protected const array DPO = ['name' => 'Nimal Silva', 'email' => 'dpo@contoso.lk', 'phone' => '+94771234567'];

    protected const array CHANGED_DPO = ['name' => 'Kamala Fernando', 'email' => 'privacy@contoso.lk', 'phone' => '+94712345678'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeFreeTierCompany();
        config(['app.debug' => false]);
    }

    #[Test]
    public function when_unauthenticated_then_the_dpo_update_is_rejected(): void
    {
        // Given a company with no DPO recorded

        // When a guest submits a DPO for it
        $response = $this->setDataProtectionOfficerAsGuest($this->company->getKey(), self::DPO);

        // Then the request is rejected as unauthenticated and no DPO is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoDpo($this->company);
    }

    #[Test]
    public function when_a_member_sets_another_companys_dpo_then_company_access_denied(): void
    {
        // Given the owner of company A, and company B
        $companyB = Company::factory()->create();

        // When owner A submits a DPO for company B
        $response = $this->setDataProtectionOfficer($this->owner, $companyB->getKey(), self::DPO);

        // Then company access is denied and company B has no DPO
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
        $this->assertNoDpo($companyB);
    }

    #[Test]
    public function when_the_requested_company_is_unknown_then_resource_not_found(): void
    {
        // Given an owner, and a company id that matches no company
        $unknownCompanyId = (string) Str::uuid();

        // When they submit a DPO for the unknown company
        $response = $this->setDataProtectionOfficer($this->owner, $unknownCompanyId, self::DPO);

        // Then the resource is not found
        $this->assertResourceNotFound($response);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutThePermission')]
    public function when_a_role_lacks_the_permission_then_the_dpo_is_refused_and_nothing_is_recorded(string $roleCode): void
    {
        // Given a member whose role does not hold companies.update
        $member = $this->memberWithRole($roleCode);

        // When they submit a DPO
        $response = $this->setDataProtectionOfficer($member, $this->company->getKey(), self::DPO);

        // Then the role is insufficient and nothing is recorded
        $this->assertRoleInsufficient($response, 'companies.update');
        $this->assertNoDpo($this->company);
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutThePermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'auditor' => ['auditor'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[Test]
    #[DataProvider('provideInvalidDpos')]
    public function when_an_input_is_invalid_then_it_is_refused_with_its_message_and_nothing_is_recorded(array $override, string $field, string $message): void
    {
        // Given the owner of a company with no DPO recorded

        // When they submit a DPO with one invalid field
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), [...self::DPO, ...$override]);

        // Then it is refused with that field's message and no DPO is recorded
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNoDpo($this->company);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidDpos(): array
    {
        return [
            'name missing' => [['name' => null], 'name', 'The data protection officer name field is required.'],
            'name not text' => [['name' => ['Nimal']], 'name', 'The data protection officer name field must be a string.'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name', 'The data protection officer name field must not be greater than 255 characters.'],
            'email missing' => [['email' => null], 'email', 'The data protection officer email field is required.'],
            'email not text' => [['email' => ['dpo@contoso.lk']], 'email', 'The data protection officer email field must be a string.'],
            'email not an address' => [['email' => 'not-an-email'], 'email', 'The data protection officer email must be a valid email address.'],
            'email too long' => [['email' => str_repeat('a', 245).'@contoso.lk'], 'email', 'The data protection officer email field must not be greater than 255 characters.'],
            'phone not text' => [['phone' => ['0771234567']], 'phone', 'The data protection officer phone field must be a string.'],
            'phone not a number' => [['phone' => '12345'], 'phone', 'The data protection officer phone must be a valid phone number.'],
            'phone outside Sri Lanka' => [['phone' => '+447911123456'], 'phone', 'The data protection officer phone must be a valid phone number.'],
            'phone too long' => [['phone' => str_repeat('1', 256)], 'phone', 'The data protection officer phone field must not be greater than 255 characters.'],
        ];
    }

    #[Test]
    #[DataProvider('provideFieldsThatCannotBeUpdated')]
    public function when_a_field_cannot_be_updated_then_it_is_refused_and_nothing_is_recorded(string $field): void
    {
        // Given the owner of a company with no DPO recorded

        // When they submit a DPO carrying a field the endpoint does not take
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), [...self::DPO, $field => 'x']);

        // Then it is refused as a field that cannot be updated and no DPO is recorded
        $this->assertValidationRejected($response, $field, 'This field cannot be updated.');
        $this->assertNoDpo($this->company);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideFieldsThatCannotBeUpdated(): array
    {
        return [
            'a mistyped phone' => ['phon'],
            'an emergency-contact field' => ['relationship'],
            'the company' => ['company'],
        ];
    }

    #[Test]
    public function when_the_dpo_projection_fails_then_nothing_is_kept(): void
    {
        // Given the owner of a company, and a projector that fails on the DPO event
        $this->induceProjectorFailureFor(CompanyDataProtectionOfficerSet::class);

        // When they submit a DPO
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), self::DPO);

        // Then the request fails, and neither the DPO, its event nor an audit row is kept
        $response->assertInternalServerError();
        $this->assertNoDpo($this->company);
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
    }

    #[Test]
    public function when_the_dpo_change_cannot_be_audited_then_nothing_is_kept(): void
    {
        // Given the owner of a company, and an audit trail that refuses writes
        $this->failTheAuditTrail();

        // When they submit a DPO
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), self::DPO);

        // Then the request fails and neither the DPO nor its event is kept
        $response->assertInternalServerError();
        $this->assertNoDpo($this->company);
    }

    #[Test]
    public function when_the_same_dpo_is_submitted_again_then_nothing_is_recorded(): void
    {
        // Given a company whose DPO is recorded
        CompanyDataProtectionOfficer::factory()->for($this->company)->create();

        // When the owner submits the same DPO
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), self::DPO);

        // Then the DPO is returned unchanged, and no event or audit row is written
        $response->assertOk();
        $response->assertExactJson(['data' => self::DPO]);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', CompanyDataProtectionOfficerSet::class)->count());
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
    }

    #[Test]
    public function when_only_the_email_changes_then_the_row_records_only_the_email(): void
    {
        // Given a company whose DPO is recorded, and an admin of it
        CompanyDataProtectionOfficer::factory()->for($this->company)->create();

        // When the admin submits the DPO with a new email
        $response = $this->setDataProtectionOfficer($this->admin, $this->company->getKey(), [...self::DPO, 'email' => self::CHANGED_DPO['email']]);

        // Then the new email is stored and the audit row records the email alone, old -> new
        $response->assertOk();
        $this->assertDpoStored($this->company, [...self::DPO, 'email' => self::CHANGED_DPO['email']]);
        $this->assertCompanyChangeAudited($this->company, AuditAction::DataProtectionOfficerSet, [
            'email' => ['from' => self::DPO['email'], 'to' => self::CHANGED_DPO['email']],
        ]);
    }

    #[Test]
    public function when_the_phone_is_left_out_over_a_recorded_phone_then_the_phone_is_cleared(): void
    {
        // Given a company whose DPO is recorded with a phone
        CompanyDataProtectionOfficer::factory()->for($this->company)->create();

        // When the owner submits the DPO without a phone
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), ['name' => self::DPO['name'], 'email' => self::DPO['email']]);

        // Then the phone is cleared, answered as none, and the audit row records it, old -> none
        $response->assertOk();
        $response->assertExactJson(['data' => [...self::DPO, 'phone' => null]]);
        $this->assertDpoStored($this->company, [...self::DPO, 'phone' => null]);
        $this->assertCompanyChangeAudited($this->company, AuditAction::DataProtectionOfficerSet, [
            'phone' => ['from' => self::DPO['phone'], 'to' => null],
        ]);
    }

    #[Test]
    public function when_the_phone_is_sent_empty_over_a_recorded_phone_then_the_phone_is_cleared(): void
    {
        // Given a company whose DPO is recorded with a phone
        CompanyDataProtectionOfficer::factory()->for($this->company)->create();

        // When the owner submits the DPO with an empty phone
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), [...self::DPO, 'phone' => null]);

        // Then the phone is cleared
        $response->assertOk();
        $this->assertDpoStored($this->company, [...self::DPO, 'phone' => null]);
    }

    #[Test]
    public function when_a_company_sets_its_dpo_then_another_company_has_none(): void
    {
        // Given the owner of two companies, neither with a DPO
        $companyB = Company::factory()->create();
        $companyB->users()->attach($this->owner, ['role_id' => $this->seededRole('owner')->getKey()]);

        // When they record a DPO for company A
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), self::DPO);

        // Then company A has the DPO and company B still has none
        $response->assertOk();
        $this->assertDpoStored($this->company, self::DPO);
        $this->assertNoDpo($companyB);
    }

    #[Test]
    public function when_the_name_is_as_long_as_allowed_then_it_is_stored_whole(): void
    {
        // Given the owner of a company with no DPO recorded
        $longestName = str_repeat('a', 255);

        // When they submit a DPO whose name is 255 characters
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), [...self::DPO, 'name' => $longestName]);

        // Then the whole name is stored
        $response->assertOk();
        $this->assertDpoStored($this->company, [...self::DPO, 'name' => $longestName]);
    }

    #[Test]
    #[DataProvider('provideLocalPhoneForms')]
    public function when_the_phone_is_written_in_a_local_form_then_it_is_stored_in_international_form(string $phone): void
    {
        // Given the owner of a company with no DPO recorded

        // When they submit a DPO whose phone is written in a local form
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), [...self::DPO, 'phone' => $phone]);

        // Then the phone is stored in international form
        $response->assertOk();
        $this->assertDpoStored($this->company, self::DPO);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideLocalPhoneForms(): array
    {
        return [
            'leading zero' => ['0771234567'],
            'leading zero with spaces' => ['077 123 4567'],
            'country code with spaces' => ['+94 77 123 4567'],
        ];
    }

    #[Test]
    public function when_the_dpo_is_saved_then_no_stored_copy_holds_plain_text(): void
    {
        // Given a company whose DPO is recorded
        CompanyDataProtectionOfficer::factory()->for($this->company)->create();

        // When the owner submits a new DPO
        $response = $this->setDataProtectionOfficer($this->owner, $this->company->getKey(), self::CHANGED_DPO);

        // Then the stored row, each old and new value in the audit row, and the recorded event
        // hold none of the values in plain text
        $response->assertOk();
        $this->assertDpoStored($this->company, self::CHANGED_DPO);
        $this->assertEncryptedChanges(AuditEntry::query()->where('action', AuditAction::DataProtectionOfficerSet)->sole(), [
            'name' => ['from' => self::DPO['name'], 'to' => self::CHANGED_DPO['name']],
            'email' => ['from' => self::DPO['email'], 'to' => self::CHANGED_DPO['email']],
            'phone' => ['from' => self::DPO['phone'], 'to' => self::CHANGED_DPO['phone']],
        ]);
        $this->assertEventHoldsNoPlainText($this->company, self::CHANGED_DPO);
    }

    #[Test]
    #[DataProvider('provideRolesWithThePermission')]
    public function when_a_role_with_the_permission_sets_the_dpo_then_it_is_recorded_stored_audited_and_returned(string $roleCode): void
    {
        // Given a member holding companies.update in a company with no DPO recorded
        $member = $this->memberWithRole($roleCode);
        $this->fromADevice();

        // When they submit the DPO
        $response = $this->setDataProtectionOfficer($member, $this->company->getKey(), self::DPO);

        // Then the DPO is returned, stored encrypted, and one audit row records it from nothing
        $response->assertOk();
        $response->assertExactJson(['data' => self::DPO]);
        $this->assertDpoStored($this->company, self::DPO);
        $this->assertCompanyChangeAudited($this->company, AuditAction::DataProtectionOfficerSet, [
            'email' => ['from' => null, 'to' => self::DPO['email']],
            'name' => ['from' => null, 'to' => self::DPO['name']],
            'phone' => ['from' => null, 'to' => self::DPO['phone']],
        ]);
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS + 1);
        $this->assertEveryRowCameFrom($response, $member, $roleCode);
        $this->assertDpoEventRecordedBy($this->company, CompanyDataProtectionOfficerSet::class, $member);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithThePermission(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
        ];
    }

    /**
     * @param  array{name: string, email: string, phone: ?string}  $plain
     */
    protected function assertEventHoldsNoPlainText(Company $company, array $plain): void
    {
        $event = LesilyStoredEvent::query()
            ->whereForCompany($company->getKey())
            ->where('event_class', CompanyDataProtectionOfficerSet::class)
            ->sole();
        $payload = json_encode($event->event_properties, JSON_THROW_ON_ERROR);

        foreach (array_filter($plain) as $value) {
            $this->assertStringNotContainsString($value, $payload);
        }
    }
}
