<?php

namespace Tests\Feature\Companies\Memberships;

use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * UC-1-069 — a company request from a member who has not accepted the live
 * Terms of Service and Privacy Policy versions is refused until they accept.
 */
class RequireCurrentConsentTest extends MembershipsTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test, DataProvider('provideBumpedPolicies')]
    public function when_the_member_has_not_accepted_a_live_policy_version_then_the_company_request_is_refused(string $policy): void
    {
        // Given a member who accepted the policies, then a new version of one is published
        $member = $this->aCompanyMember($this->company);
        config(["lesily.policies.{$policy}" => 'v1.1']);

        // When they request the company
        $response = $this->viewCompany($member, $this->company);

        // Then the request is refused until they accept
        $this->assertConsentRequired($response);
    }

    #[Test]
    public function when_an_invited_member_has_no_consent_then_the_company_request_is_refused(): void
    {
        // Given someone who joined the company by accepting an invitation
        $admin = $this->aCompanyMember($this->company, 'admin');
        [$joiner] = $this->anAcceptedMembership($this->company, $admin, 'nimal@contoso.lk');

        // When they request the company
        $response = $this->viewCompany($joiner, $this->company);

        // Then the request is refused until they accept
        $this->assertConsentRequired($response);
    }

    #[Test]
    public function when_the_member_accepted_the_live_versions_in_another_company_only_then_the_company_request_is_refused(): void
    {
        // Given a member of two companies who accepted a new privacy policy version in the second only
        $member = $this->aCompanyMember($this->company);
        $second = Company::factory()->create();
        $this->attachMember($second, $member, $this->seededRole('employee'));
        config(['lesily.policies.privacy' => 'v1.1']);
        $this->aConsentRecordedBy($member, $second);

        // When they request the first company
        $response = $this->viewCompany($member, $this->company);

        // Then the request is refused until they accept there too
        $this->assertConsentRequired($response);
    }

    #[Test]
    public function when_refused_then_the_member_can_still_read_their_own_profile(): void
    {
        // Given a member refused by a new privacy policy version
        $member = $this->aCompanyMember($this->company);
        config(['lesily.policies.privacy' => 'v1.1']);
        Sanctum::actingAs($member);

        // When they read their own profile
        $response = $this->getJson('/api/v1/me');

        // Then it is open to them
        $response->assertOk();
    }

    #[Test]
    public function when_refused_then_the_member_can_still_sign_out(): void
    {
        // Given a member refused by a new privacy policy version, signed in on a session
        $member = $this->aCompanyMember($this->company);
        config(['lesily.policies.privacy' => 'v1.1']);
        $session = $this->aSessionFor($member);

        // When they sign out
        $response = $this->withToken($session->plainTextToken)->postJson('/api/v1/auth/logout');

        // Then they are signed out
        $response->assertNoContent();
    }

    #[Test]
    public function when_the_member_has_accepted_the_live_versions_then_the_company_request_proceeds(): void
    {
        // Given a member who accepted the live policy versions
        $member = $this->aCompanyMember($this->company);

        // When they request the company
        $response = $this->viewCompany($member, $this->company);

        // Then the request proceeds
        $response->assertOk();
    }

    public static function provideBumpedPolicies(): array
    {
        return [
            'tos bumped' => ['tos'],
            'privacy bumped' => ['privacy'],
        ];
    }
}
