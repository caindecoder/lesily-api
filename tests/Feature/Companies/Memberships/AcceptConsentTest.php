<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;

/**
 * UC-1-069 — a member accepts the live Terms of Service and Privacy Policy
 * versions for a company, recorded with the IP they accepted from.
 */
class AcceptConsentTest extends MembershipsTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test]
    public function when_a_non_member_accepts_then_access_is_denied_and_nothing_is_recorded(): void
    {
        // Given someone who is not a member of the company
        $outsider = User::factory()->create();

        // When they accept the policies for it
        $response = $this->acceptConsent($outsider, $this->company);

        // Then access is denied and no consent is kept for them
        $this->assertCompanyAccessDenied($response);
        $this->assertDatabaseMissing('consent_records', ['user_id' => $outsider->getKey()]);
    }

    #[Test]
    public function when_the_request_carries_no_ip_then_the_accept_is_refused_and_nothing_is_recorded(): void
    {
        // Given a member facing a new privacy policy version, from an undeterminable address
        $member = $this->aCompanyMember($this->company);
        config(['lesily.policies.privacy' => 'v1.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '']);

        // When they accept the policies
        $response = $this->acceptConsent($member, $this->company);

        // Then the accept is refused as invalid and the new version is not kept
        $this->assertValidationRejected($response, 'ip', 'The ip field is required.');
        $this->assertDatabaseMissing('consent_records', ['user_id' => $member->getKey(), 'version' => 'v1.1']);
    }

    #[Test]
    public function when_a_member_accepts_in_one_company_then_another_company_stays_refused(): void
    {
        // Given a member of two companies facing a new privacy policy version
        $member = $this->aCompanyMember($this->company);
        $second = Company::factory()->create();
        $this->attachMember($second, $member, $this->seededRole('employee'));
        config(['lesily.policies.privacy' => 'v1.1']);

        // When they accept the policies for the first company
        $this->acceptConsent($member, $this->company);

        // Then the new version is kept for the first company only
        $this->assertDatabaseHas('consent_records', ['user_id' => $member->getKey(), 'company_id' => $this->company->getKey(), 'version' => 'v1.1']);
        $this->assertDatabaseMissing('consent_records', ['user_id' => $member->getKey(), 'company_id' => $second->getKey(), 'version' => 'v1.1']);
    }

    #[Test]
    public function when_an_invited_member_accepts_then_the_company_opens_to_them(): void
    {
        // Given someone who joined by invitation and then accepted the policies
        $admin = $this->aCompanyMember($this->company, 'admin');
        [$joiner] = $this->anAcceptedMembership($this->company, $admin, 'nimal@contoso.lk');
        $this->acceptConsent($joiner, $this->company);

        // When they request the company
        $response = $this->viewCompany($joiner, $this->company);

        // Then the request proceeds
        $response->assertOk();
    }

    #[Test]
    public function when_a_member_accepts_then_both_live_versions_are_recorded_with_the_ip(): void
    {
        // Given a member facing new versions of both policies, from a known address
        $member = $this->aCompanyMember($this->company);
        config(['lesily.policies.tos' => 'v1.1', 'lesily.policies.privacy' => 'v1.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        // When they accept the policies
        $response = $this->acceptConsent($member, $this->company);

        // Then both live versions are recorded for them in this company, with that address
        $response->assertNoContent();
        $this->assertLiveConsentsRecorded($member, $this->company, '203.0.113.9');
    }
}
