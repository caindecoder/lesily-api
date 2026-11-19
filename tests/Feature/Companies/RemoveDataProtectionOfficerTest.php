<?php

namespace Tests\Feature\Companies;

use Illuminate\Support\Str;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Companies\Entities\CompanyDataProtectionOfficer;
use App\Companies\Events\CompanyDataProtectionOfficerRemoved;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * UC-1-146 alt 3c / 3d — the owner or an admin removes the company's data protection
 * officer: DELETE /api/v1/companies/{companyId}/data-protection-officer.
 */
class RemoveDataProtectionOfficerTest extends CompaniesTestCase
{
    protected const array DPO = ['name' => 'Nimal Silva', 'email' => 'dpo@contoso.lk', 'phone' => '+94771234567'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
        config(['app.debug' => false]);
    }

    #[Test]
    public function when_unauthenticated_then_the_removal_is_rejected(): void
    {
        // Given a company whose DPO is recorded
        $company = Company::factory()->create();
        CompanyDataProtectionOfficer::factory()->for($company)->create();

        // When a guest removes it
        $response = $this->removeDataProtectionOfficerAsGuest($company->getKey());

        // Then the request is rejected as unauthenticated and the DPO is kept
        $this->assertUnauthenticated($response);
        $this->assertDpoStored($company, self::DPO);
    }

    #[Test]
    public function when_a_member_removes_another_companys_dpo_then_company_access_denied(): void
    {
        // Given the owner of company A, and company B whose DPO is recorded
        $ownerA = $this->aCompanyMember(Company::factory()->create(), 'owner');
        $companyB = Company::factory()->create();
        CompanyDataProtectionOfficer::factory()->for($companyB)->create();

        // When owner A removes company B's DPO
        $response = $this->removeDataProtectionOfficer($ownerA, $companyB->getKey());

        // Then company access is denied and company B's DPO is kept
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
        $this->assertDpoStored($companyB, self::DPO);
    }

    #[Test]
    public function when_the_requested_company_is_unknown_then_resource_not_found(): void
    {
        // Given an owner, and a company id that matches no company
        $owner = $this->aCompanyMember(Company::factory()->create(), 'owner');

        // When they remove the unknown company's DPO
        $response = $this->removeDataProtectionOfficer($owner, (string) Str::uuid());

        // Then the resource is not found
        $this->assertResourceNotFound($response);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutThePermission')]
    public function when_a_role_lacks_the_permission_then_the_removal_is_refused_and_the_dpo_kept(string $roleCode): void
    {
        // Given a member whose role does not hold companies.update, in a company whose DPO is recorded
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company, $roleCode);
        CompanyDataProtectionOfficer::factory()->for($company)->create();

        // When they remove the DPO
        $response = $this->removeDataProtectionOfficer($member, $company->getKey());

        // Then the role is insufficient, the DPO is kept and nothing is audited
        $this->assertRoleInsufficient($response, 'companies.update');
        $this->assertDpoStored($company, self::DPO);
        $this->assertNothingAudited();
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

    #[Test]
    public function when_the_removal_cannot_be_projected_then_the_dpo_is_kept(): void
    {
        // Given a company whose DPO is recorded, and a projector that fails on the removal
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        CompanyDataProtectionOfficer::factory()->for($company)->create();
        $this->induceProjectorFailureFor(CompanyDataProtectionOfficerRemoved::class);

        // When the owner removes the DPO
        $response = $this->removeDataProtectionOfficer($owner, $company->getKey());

        // Then the request fails, and the DPO is kept with no removal event or audit row
        $response->assertInternalServerError();
        $this->assertDpoStored($company, self::DPO);
        $this->assertNoRemovalRecorded($company);
    }

    #[Test]
    public function when_the_removal_cannot_be_audited_then_the_dpo_is_kept(): void
    {
        // Given a company whose DPO is recorded, and an audit trail that refuses writes
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        CompanyDataProtectionOfficer::factory()->for($company)->create();
        $this->failTheAuditTrail();

        // When the owner removes the DPO
        $response = $this->removeDataProtectionOfficer($owner, $company->getKey());

        // Then the request fails, and the DPO is kept with no removal event
        $response->assertInternalServerError();
        $this->assertDpoStored($company, self::DPO);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', CompanyDataProtectionOfficerRemoved::class)->count());
    }

    #[Test]
    public function when_no_dpo_is_recorded_then_nothing_is_recorded(): void
    {
        // Given the owner of a company with no DPO recorded
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When they remove the DPO
        $response = $this->removeDataProtectionOfficer($owner, $company->getKey());

        // Then the removal answers with no content, and nothing is recorded
        $response->assertNoContent();
        $this->assertNoRemovalRecorded($company);
    }

    #[Test]
    public function when_a_company_removes_its_dpo_then_another_company_keeps_its_own(): void
    {
        // Given the owner of two companies, each with its own DPO
        $companyA = Company::factory()->create();
        $owner = $this->aCompanyMember($companyA, 'owner');
        $companyB = Company::factory()->create();
        $companyB->users()->attach($owner, ['role_id' => $this->seededRole('owner')->getKey()]);
        CompanyDataProtectionOfficer::factory()->for($companyA)->create();
        CompanyDataProtectionOfficer::factory()->for($companyB)->create();

        // When they remove company A's DPO
        $response = $this->removeDataProtectionOfficer($owner, $companyA->getKey());

        // Then company A has none and company B keeps its own
        $response->assertNoContent();
        $this->assertFalse(CompanyDataProtectionOfficer::query()->where('company_id', $companyA->getKey())->exists());
        $this->assertDpoStored($companyB, self::DPO);
    }

    #[Test]
    public function when_the_dpo_is_removed_then_the_audit_row_holds_no_plain_text(): void
    {
        // Given a company whose DPO is recorded
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        CompanyDataProtectionOfficer::factory()->for($company)->create();

        // When the owner removes the DPO
        $response = $this->removeDataProtectionOfficer($owner, $company->getKey());

        // Then the removal is filed under the company, and each old value in its audit row is encrypted
        $response->assertNoContent();
        $this->assertSame(1, LesilyStoredEvent::query()
            ->whereForCompany($company->getKey())
            ->where('event_class', CompanyDataProtectionOfficerRemoved::class)
            ->count());
        $this->assertEncryptedChanges(AuditEntry::query()->where('action', AuditAction::DataProtectionOfficerRemoved)->sole(), [
            'name' => ['from' => self::DPO['name'], 'to' => null],
            'email' => ['from' => self::DPO['email'], 'to' => null],
            'phone' => ['from' => self::DPO['phone'], 'to' => null],
        ]);
    }

    #[Test]
    #[DataProvider('provideRolesWithThePermission')]
    public function when_a_role_with_the_permission_removes_the_dpo_then_it_is_recorded_gone_and_audited(string $roleCode): void
    {
        // Given a member holding companies.update, in a company whose DPO is recorded
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company, $roleCode);
        CompanyDataProtectionOfficer::factory()->for($company)->create();
        $this->fromADevice();

        // When they remove the DPO
        $response = $this->removeDataProtectionOfficer($member, $company->getKey());

        // Then it is gone, the removal is recorded, and one audit row records the old values going
        $response->assertNoContent();
        $this->assertFalse(CompanyDataProtectionOfficer::query()->where('company_id', $company->getKey())->exists());
        $this->assertCompanyChangeAudited($company, AuditAction::DataProtectionOfficerRemoved, [
            'email' => ['from' => self::DPO['email'], 'to' => null],
            'name' => ['from' => self::DPO['name'], 'to' => null],
            'phone' => ['from' => self::DPO['phone'], 'to' => null],
        ]);
        $this->assertEveryRowCameFrom($response, $member, $roleCode);
        $this->assertDpoEventRecordedBy($company, CompanyDataProtectionOfficerRemoved::class, $member);
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

    protected function assertNoRemovalRecorded(Company $company): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $company->getKey())
            ->where('event_class', CompanyDataProtectionOfficerRemoved::class)
            ->count());
        $this->assertNothingAudited();
    }
}
