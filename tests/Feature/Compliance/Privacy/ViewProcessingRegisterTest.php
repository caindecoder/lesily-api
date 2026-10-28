<?php

namespace Tests\Feature\Compliance\Privacy;

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class ViewProcessingRegisterTest extends PrivacyTestCase
{
    #[Test]
    public function when_no_token_is_sent_then_the_request_is_unauthenticated(): void
    {
        // Given a company with an owner member
        [$company] = $this->aCompanyWithMember();

        // When its processing register is requested with no token
        $response = $this->getJson('/api/v1/privacy/processing-register', ['X-Company-Context' => $company->getKey()]);

        // Then the request is unauthenticated
        $this->assertRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::Unauthenticated);
    }

    #[Test]
    public function when_the_caller_is_not_a_member_of_the_company_then_company_access_is_denied(): void
    {
        // Given an auditor of one company and a second company they never joined
        [, $outsider] = $this->aCompanyWithMember('auditor');
        [$otherCompany] = $this->aCompanyWithMember();

        // When they view the second company's processing register
        $response = $this->viewRegister($outsider, $otherCompany);

        // Then the company context is denied
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
    }

    #[Test]
    public function when_a_member_owes_a_new_policy_version_then_consent_is_required(): void
    {
        // Given an admin who accepted the policies, then a new privacy policy version is published
        [$company, $admin] = $this->aCompanyWithMember('admin');
        config(['lesily.policies.privacy' => 'v1.1']);

        // When they view the processing register
        $response = $this->viewRegister($admin, $company);

        // Then the register is refused until they accept
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::ConsentRequired);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutRegisterPermission')]
    public function when_a_role_without_the_register_permission_views_it_then_the_role_is_insufficient(string $roleCode): void
    {
        // Given a member whose role does not carry privacy.view-register
        [$company, $actor] = $this->aCompanyWithMember($roleCode);

        // When they view the processing register
        $response = $this->viewRegister($actor, $company);

        // Then the register is refused for the missing permission
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::RoleInsufficient);
    }

    #[Test]
    public function when_a_write_verb_is_sent_then_the_method_is_not_allowed(): void
    {
        // Given an owner of a company
        [$company, $owner] = $this->aCompanyWithMember();

        // When they try to PATCH the processing register
        $response = $this->sendToRegister('PATCH', $owner, $company);

        // Then the register is not editable
        $this->assertRefusedWith($response, Response::HTTP_METHOD_NOT_ALLOWED, ApiErrorCode::HttpError);
    }

    #[Test]
    public function when_the_owner_views_the_register_then_every_foundation_activity_is_listed_in_order_with_its_words(): void
    {
        // Given an owner of a company
        [$company, $owner] = $this->aCompanyWithMember();

        // When they view the processing register
        $response = $this->viewRegister($owner, $company);

        // Then every Foundation processing activity is listed in module order, in words
        $this->assertFoundationRegister($response);
    }

    #[Test]
    #[DataProvider('provideOtherRolesHoldingTheRegisterPermission')]
    public function when_another_role_holding_the_permission_views_the_register_then_the_same_activities_are_listed(string $roleCode): void
    {
        // Given a member whose role carries privacy.view-register
        [$company, $actor] = $this->aCompanyWithMember($roleCode);

        // When they view the processing register
        $response = $this->viewRegister($actor, $company);

        // Then they see the same Foundation processing activities as the owner
        $this->assertFoundationRegister($response);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutRegisterPermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideOtherRolesHoldingTheRegisterPermission(): array
    {
        return [
            'admin' => ['admin'],
            'auditor' => ['auditor'],
        ];
    }

    protected function assertFoundationRegister(TestResponse $response): void
    {
        $response->assertOk();
        $entries = $response->json('data');
        $this->assertSame([
            'accounts.sign_in',
            'accounts.security_records',
            'companies.account',
            'companies.emergency_contact',
            'companies.consent_records',
            'billing.invoices',
            'audit.audit_trail',
            'audit.access_requests',
        ], array_column($entries, 'key'));
        $this->assertAccountsActivities(array_slice($entries, 0, 2));
        $this->assertCompaniesActivities(array_slice($entries, 2, 3));
        $this->assertBillingActivities(array_slice($entries, 5, 1));
        $this->assertAuditActivities(array_slice($entries, 6, 2));
    }

    protected function assertAccountsActivities(array $entries): void
    {
        $this->assertSame([
            'key' => 'accounts.sign_in',
            'module' => 'Accounts',
            'activity' => 'Sign-in and account',
            'data_categories' => ['Name', 'Email address', 'Password (hashed)', 'Two-factor secret (encrypted)', 'Sign-in sessions'],
            'purpose' => 'Sign members in and keep their accounts secure',
            'legal_basis' => 'Contract',
            'retention' => 'While the account is open; after it closes, deleted on the account-closure schedule',
            'third_parties' => ['Hosting provider', 'Email delivery service', 'Error monitoring'],
        ], $entries[0]);
        $this->assertSame([
            'key' => 'accounts.security_records',
            'module' => 'Accounts',
            'activity' => 'Sign-in and refusal records',
            'data_categories' => ['Sign-in records', 'Refused-request records', 'IP address'],
            'purpose' => 'Protect accounts and the service against misuse',
            'legal_basis' => 'Legitimate interests',
            'retention' => '1 year on Free and Starter, 3 years on Pro, 7 years on Enterprise',
            'third_parties' => ['Hosting provider'],
        ], $entries[1]);
    }

    protected function assertCompaniesActivities(array $entries): void
    {
        $this->assertSame([
            'key' => 'companies.account',
            'module' => 'Companies',
            'activity' => 'Company account',
            'data_categories' => ['Company identity (name, BR number, EPF number, country)', 'Memberships and roles', 'Invitations (email address)'],
            'purpose' => "Run the company's account, its members and their roles",
            'legal_basis' => 'Contract',
            'retention' => self::KEPT_UNTIL_ACCOUNT_CLOSURE,
            'third_parties' => ['Hosting provider', 'Email delivery service'],
        ], $entries[0]);
        $this->assertSame([
            'key' => 'companies.emergency_contact',
            'module' => 'Companies',
            'activity' => "Owner's emergency contact",
            'data_categories' => ["Emergency contact's name, phone and relationship (encrypted)"],
            'purpose' => 'Keep the company running if its owner dies or cannot act',
            'legal_basis' => 'Legitimate interests',
            'retention' => self::KEPT_UNTIL_ACCOUNT_CLOSURE,
            'third_parties' => ['Hosting provider'],
        ], $entries[1]);
        $this->assertSame([
            'key' => 'companies.consent_records',
            'module' => 'Companies',
            'activity' => 'Consent records',
            'data_categories' => ['Accepted policy versions', 'IP address'],
            'purpose' => 'Prove which policy versions each member accepted',
            'legal_basis' => 'Legal obligation',
            'retention' => self::KEPT_UNTIL_ACCOUNT_CLOSURE,
            'third_parties' => ['Hosting provider'],
        ], $entries[2]);
    }

    protected function assertBillingActivities(array $entries): void
    {
        $this->assertSame([
            'key' => 'billing.invoices',
            'module' => 'Billing',
            'activity' => 'Invoices',
            'data_categories' => ['Invoices', 'Billing address', 'Payment references'],
            'purpose' => "Bill the company's subscription",
            'legal_basis' => 'Contract',
            'retention' => '10 years (Companies Act), anonymized when the company is deleted',
            'third_parties' => ['Payment processor', 'Hosting provider'],
        ], $entries[0]);
    }

    protected function assertAuditActivities(array $entries): void
    {
        $this->assertSame([
            'key' => 'audit.audit_trail',
            'module' => 'Audit and privacy',
            'activity' => 'Audit trail',
            'data_categories' => ['Who changed what, and when'],
            'purpose' => 'Account for every change to personal data',
            'legal_basis' => 'Legal obligation',
            'retention' => 'Kept indefinitely; never deleted',
            'third_parties' => ['Hosting provider'],
        ], $entries[0]);
        $this->assertSame([
            'key' => 'audit.access_requests',
            'module' => 'Audit and privacy',
            'activity' => 'Access requests',
            'data_categories' => ['Access requests', 'Access request exports'],
            'purpose' => "Answer members' requests for a copy of their data",
            'legal_basis' => 'Legal obligation',
            'retention' => 'The export is deleted 7 days after it is ready; the request is kept with the audit trail',
            'third_parties' => ['Hosting provider'],
        ], $entries[1]);
    }
}
