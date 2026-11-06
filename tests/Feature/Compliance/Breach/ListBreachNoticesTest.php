<?php

namespace Tests\Feature\Compliance\Breach;

use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class ListBreachNoticesTest extends BreachIncidentsTestCase
{
    #[Test]
    public function when_a_guest_lists_breach_notices_then_they_are_unauthenticated(): void
    {
        // Given company A was notified of a breach
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, now()->toImmutable());

        // When a caller with no token lists company A's breach notices
        $response = $this->listNotices(null, $this->companyA);

        // Then they are refused as unauthenticated and no notice is shown
        $this->assertUnauthenticated($response);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    #[DataProvider('provideRolesWithoutTheViewPermission')]
    public function when_a_member_without_the_view_permission_lists_then_the_role_is_insufficient(string $roleCode): void
    {
        // Given company A was notified of a breach, and a member of A whose role cannot view breach notices
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, now()->toImmutable());
        $member = $this->aConsentingMember($this->companyA, $roleCode);

        // When the member lists company A's breach notices
        $response = $this->listNotices($member, $this->companyA);

        // Then the role is refused and no notice is shown
        $this->assertRoleInsufficient($response, 'breach.view-notices');
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_a_member_of_another_company_lists_then_company_access_is_denied(): void
    {
        // Given company A was notified of a breach, and the owner of company B
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, now()->toImmutable());
        $outsider = $this->aConsentingMember($this->companyB, 'owner');

        // When B's owner lists company A's breach notices
        $response = $this->listNotices($outsider, $this->companyA);

        // Then access to company A is denied and no notice is shown
        $this->assertCompanyAccessDenied($response);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_a_member_owes_a_new_policy_version_then_consent_is_required(): void
    {
        // Given company A was notified of a breach, and A's owner accepted the policies before a new privacy policy version
        $this->aNoticeFor($this->anIncident()->companiesNotified(), $this->companyA, now()->toImmutable());
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        config(['lesily.policies.privacy' => 'v1.1']);

        // When the owner lists company A's breach notices
        $response = $this->listNotices($owner, $this->companyA);

        // Then the list is refused until they accept, and no notice is shown
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::ConsentRequired);
        $response->assertJsonMissingPath('data');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutTheViewPermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }
}
