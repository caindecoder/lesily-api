<?php

namespace Tests\Feature\Companies\Memberships;

use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * UC-1-029 — the owner reads one company's emergency contact:
 * GET /api/v1/companies/{companyId}/emergency-contact, owner-only by permission.
 */
class ViewEmergencyContactTest extends MembershipsTestCase
{
    #[Test]
    public function when_unauthenticated_then_the_contact_request_is_rejected(): void
    {
        // Given a company and no signed-in person
        $company = Company::factory()->create();

        // When a guest asks for its emergency contact
        $response = $this->viewEmergencyContactAsGuest($company->getKey());

        // Then the request is rejected as unauthenticated
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_a_member_requests_another_companys_contact_then_company_access_denied(): void
    {
        // Given the owner of company A and company B, which has its own owner and contact
        $companyA = Company::factory()->create();
        $ownerA = $this->anOwnerWithContact($companyA);
        $companyB = Company::factory()->create();
        $this->anOwnerWithContact($companyB, self::UPDATED_CONTACT);

        // When owner A asks for company B's emergency contact
        $response = $this->viewEmergencyContact($ownerA, $companyB->getKey());

        // Then company access is denied
        $this->assertCompanyAccessDenied($response);
    }

    #[Test]
    public function when_the_requested_company_is_unknown_then_resource_not_found(): void
    {
        // Given an owner and a company id nobody holds
        $company = Company::factory()->create();
        $owner = $this->anOwnerWithContact($company);

        // When the owner asks for that company's contact
        $response = $this->viewEmergencyContact($owner, Str::uuid7()->toString());

        // Then the company is not found
        $this->assertResourceNotFound($response);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutThePermission')]
    public function when_a_role_lacks_the_permission_then_the_contact_is_withheld(string $roleCode): void
    {
        // Given a company whose owner keeps a contact, and a member in another role
        $company = Company::factory()->create();
        $this->anOwnerWithContact($company);
        $member = $this->aCompanyMember($company, $roleCode);

        // When the member asks for the emergency contact
        $response = $this->viewEmergencyContact($member, $company->getKey());

        // Then the request is refused for the missing permission and no contact is shown
        $this->assertRoleInsufficient($response, 'users.set-emergency-contact');
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_the_owner_has_no_contact_then_it_is_not_found(): void
    {
        // Given a company whose owner keeps no emergency contact
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When the owner asks for it
        $response = $this->viewEmergencyContact($owner, $company->getKey());

        // Then there is no contact to return
        $this->assertResourceNotFound($response);
    }

    #[Test]
    public function when_the_owner_of_two_companies_views_one_then_only_that_companys_contact_is_returned(): void
    {
        // Given one owner of company A (Kamala) and company B (John)
        $companyA = Company::factory()->create();
        $owner = $this->anOwnerWithContact($companyA);
        $companyB = Company::factory()->create();
        $this->enrolAsOwner($companyB, $owner, self::UPDATED_CONTACT);

        // When the owner asks for company B's contact
        $response = $this->viewEmergencyContact($owner, $companyB->getKey());

        // Then company B's contact is returned
        $response->assertOk();
        $response->assertExactJson(['data' => self::UPDATED_CONTACT]);
    }

    #[Test]
    public function when_another_member_keeps_a_contact_then_the_owner_is_shown_only_their_own(): void
    {
        // Given a former owner, now an admin, who keeps John, and the owner who keeps Kamala
        $company = Company::factory()->create();
        $this->aMemberWithContact($company, 'admin', self::UPDATED_CONTACT);
        $owner = $this->anOwnerWithContact($company);

        // When the owner asks for the company's contact
        $response = $this->viewEmergencyContact($owner, $company->getKey());

        // Then the owner's own contact is returned
        $response->assertOk();
        $response->assertExactJson(['data' => self::CONTACT]);
    }

    #[Test]
    public function when_the_owner_views_the_contact_then_it_is_returned(): void
    {
        // Given a company whose owner keeps an emergency contact
        $company = Company::factory()->create();
        $owner = $this->anOwnerWithContact($company);

        // When the owner asks for it
        $response = $this->viewEmergencyContact($owner, $company->getKey());

        // Then the contact is returned
        $response->assertOk();
        $response->assertExactJson(['data' => self::CONTACT]);
    }
}
