<?php

namespace Tests\Feature\Compliance\Privacy;

use App\Models\User;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class ViewPrivacyNoticeTest extends PrivacyTestCase
{
    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->named('Asha Perera')->withEmail('asha@contoso.lk')->create();
        $this->company = Company::factory()
            ->named('Contoso (Pvt) Ltd')
            ->registeredAs('PV-00012345', 'EPF-0012345')
            ->ownedBy($this->owner)
            ->create();
        $this->attachMember($this->company, $this->owner, $this->seededRole('owner'));
    }

    #[Test]
    public function when_no_token_is_sent_then_the_request_is_unauthenticated(): void
    {
        // Given Contoso and its owner

        // When its privacy notice is requested with no token
        $response = $this->getJson('/api/v1/privacy/notice', ['X-Company-Context' => $this->company->getKey()]);

        // Then the request is unauthenticated
        $this->assertRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::Unauthenticated);
    }

    #[Test]
    public function when_the_caller_is_not_a_member_of_the_company_then_company_access_is_denied(): void
    {
        // Given an employee of another company, never a member of Contoso
        [, $outsider] = $this->aCompanyWithMember('employee');

        // When they view Contoso's privacy notice
        $response = $this->viewNotice($outsider, $this->company);

        // Then company access is denied
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
    }

    #[Test]
    public function when_a_member_owes_a_new_policy_version_then_the_notice_still_reads(): void
    {
        // Given an employee of Contoso
        $employee = User::factory()->create();
        $this->attachMember($this->company, $employee, $this->seededRole('employee'));
        // And a new privacy-policy version is live that they have not accepted
        config(['lesily.policies.privacy' => 'v1.1']);

        // When they view the privacy notice
        $response = $this->viewNotice($employee, $this->company);

        // Then they still read all thirteen items
        $this->assertThirteenItemsInOrder($response);
    }

    #[Test]
    public function when_the_company_has_no_owner_then_the_controller_item_says_no_contact_is_on_record(): void
    {
        // Given a company where no member holds the owner role
        $company = Company::factory()->named('Northwind (Pvt) Ltd')->registeredAs('PV-00054321', 'EPF-0054321')->create();
        $employee = User::factory()->create();
        $this->attachMember($company, $employee, $this->seededRole('employee'));

        // When its employee views the privacy notice
        $response = $this->viewNotice($employee, $company);

        // Then item 1 names the company and says no contact is on record
        $this->assertSame([
            'Northwind (Pvt) Ltd, BR number PV-00054321, Sri Lanka',
            'No contact is on record; ask your employer',
        ], $this->itemsOf($response)['controller']['body']);
    }

    #[Test]
    public function when_the_owner_also_owns_another_company_then_only_this_company_appears(): void
    {
        // Given Asha Perera also owns Fabrikam
        $fabrikam = Company::factory()
            ->named('Fabrikam (Pvt) Ltd')
            ->registeredAs('PV-00099999', 'EPF-0099999')
            ->ownedBy($this->owner)
            ->create();
        $this->attachMember($fabrikam, $this->owner, $this->seededRole('owner'));

        // When she views Contoso's privacy notice
        $response = $this->viewNotice($this->owner, $this->company);

        // Then only Contoso appears; nothing of Fabrikam does
        $this->assertOnlyThisCompanyAppears($response);
    }

    #[Test, DataProvider('provideEveryRole')]
    public function when_any_member_views_the_notice_then_all_thirteen_items_appear_in_order(string $roleCode): void
    {
        // Given a member of Contoso holding the role
        $member = User::factory()->create();
        $this->attachMember($this->company, $member, $this->seededRole($roleCode));

        // When they view the privacy notice
        $response = $this->viewNotice($member, $this->company);

        // Then all thirteen Schedule V items appear, in order
        $this->assertThirteenItemsInOrder($response);
    }

    #[Test]
    public function when_the_notice_is_viewed_then_the_controller_item_names_the_company_and_its_owner(): void
    {
        // Given an employee of Contoso, whose owner is Asha Perera
        $employee = User::factory()->create();
        $this->attachMember($this->company, $employee, $this->seededRole('employee'));

        // When they view the privacy notice
        $response = $this->viewNotice($employee, $this->company);

        // Then item 1 names Contoso, its BR number and country, with Asha Perera as the contact
        // And item 2 says no data protection officer is designated
        $this->assertControllerItems($response);
    }

    #[Test]
    public function when_the_notice_is_viewed_then_the_register_items_cover_every_foundation_activity(): void
    {
        // Given an employee of Contoso
        $employee = User::factory()->create();
        $this->attachMember($this->company, $employee, $this->seededRole('employee'));

        // When they view the privacy notice
        $response = $this->viewNotice($employee, $this->company);

        // Then the purposes, legitimate interests and retention items name every Foundation activity
        $this->assertActivityItems($response);
        // And the data and recipients items list each category once
        $this->assertListItems($response);
    }

    #[Test]
    public function when_the_notice_is_viewed_then_the_fixed_items_carry_the_drafted_wording(): void
    {
        // Given an employee of Contoso
        $employee = User::factory()->create();
        $this->attachMember($this->company, $employee, $this->seededRole('employee'));

        // When they view the privacy notice
        $response = $this->viewNotice($employee, $this->company);

        // Then the consent, rights and complaint items carry the drafted wording
        $this->assertRightsItems($response);
        // And so do the transfer, requirement and automated-decision items
        $this->assertDisclosureItems($response);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEveryRole(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'auditor' => ['auditor'],
            'manager' => ['manager'],
        ];
    }

    protected function assertThirteenItemsInOrder(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame([
            'controller' => 'Who controls your data',
            'dpo' => 'Data protection officer',
            'purposes' => 'Purposes and legal basis',
            'legitimate_interests' => 'Legitimate interests',
            'data_categories' => 'Personal data we process',
            'withdraw_consent' => 'Withdrawing consent',
            'recipients' => 'Who receives your data',
            'cross_border_transfer' => 'Where your data is processed',
            'retention' => 'How long it is kept',
            'data_subject_rights' => 'Your rights and how to use them',
            'complaint' => 'Complaints',
            'statutory_or_contractual' => 'Is providing the data required?',
            'automated_decisions' => 'Automated decisions',
        ], array_column($response->json('data'), 'heading', 'key'));
    }

    protected function assertControllerItems(TestResponse $response): void
    {
        $items = $this->itemsOf($response);

        $this->assertSame([
            'Contoso (Pvt) Ltd, BR number PV-00012345, Sri Lanka',
            'Contact: Asha Perera, asha@contoso.lk',
        ], $items['controller']['body']);
        $this->assertSame([
            'No data protection officer has been designated; contact the controller above',
        ], $items['dpo']['body']);
    }

    protected function assertActivityItems(TestResponse $response): void
    {
        $items = $this->itemsOf($response);

        $this->assertSame([
            'Sign-in and account: Sign members in and keep their accounts secure (Contract)',
            'Sign-in and refusal records: Protect accounts and the service against misuse (Legitimate interests)',
            "Company account: Run the company's account, its members and their roles (Contract)",
            "Owner's emergency contact: Keep the company running if its owner dies or cannot act (Legitimate interests)",
            'Consent records: Prove which policy versions each member accepted (Legal obligation)',
            "Invoices: Bill the company's subscription (Contract)",
            'Audit trail: Account for every change to personal data (Legal obligation)',
            "Access requests: Answer members' requests for a copy of their data (Legal obligation)",
        ], $items['purposes']['body']);
        $this->assertSame([
            'Sign-in and refusal records: Protect accounts and the service against misuse',
            "Owner's emergency contact: Keep the company running if its owner dies or cannot act",
        ], $items['legitimate_interests']['body']);
        $this->assertSame([
            'Sign-in and account: While the account is open; after it closes, deleted on the account-closure schedule',
            'Sign-in and refusal records: 1 year on Free and Starter, 3 years on Pro, 7 years on Enterprise',
            'Company account: '.self::KEPT_UNTIL_ACCOUNT_CLOSURE,
            "Owner's emergency contact: ".self::KEPT_UNTIL_ACCOUNT_CLOSURE,
            'Consent records: '.self::KEPT_UNTIL_ACCOUNT_CLOSURE,
            'Invoices: 10 years (Companies Act), anonymized when the company is deleted',
            'Audit trail: Kept indefinitely; never deleted',
            'Access requests: The export is deleted 7 days after it is ready; the request is kept with the audit trail',
        ], $items['retention']['body']);
    }

    protected function assertListItems(TestResponse $response): void
    {
        $items = $this->itemsOf($response);

        $this->assertSame([
            'Name',
            'Email address',
            'Password (hashed)',
            'Two-factor secret (encrypted)',
            'Sign-in sessions',
            'Linked Google account ID',
            'Sign-in records',
            'Refused-request records',
            'IP address',
            'Company identity (name, BR number, EPF number, country)',
            'Memberships and roles',
            'Invitations (email address)',
            "Emergency contact's name, phone and relationship (encrypted)",
            'Accepted policy versions',
            'Invoices',
            'Billing address',
            'Payment references',
            'Who changed what, and when',
            'Access requests',
            'Access request exports',
        ], $items['data_categories']['body']);
        $this->assertSame([
            'Hosting provider',
            'Email delivery service',
            'Error monitoring',
            'Sign-in provider',
            'Payment processor',
        ], $items['recipients']['body']);
    }

    protected function assertRightsItems(TestResponse $response): void
    {
        $items = $this->itemsOf($response);

        $this->assertSame([
            'Where processing relies on your consent, you can withdraw it at any time by contacting the controller above; this does not affect processing done before.',
        ], $items['withdraw_consent']['body']);
        $this->assertSame([
            'You can ask for a copy of your data in Lesily (Privacy → Access requests).',
            'To have your data corrected or erased, or to object to its processing, contact the controller above.',
            'The controller answers within 21 working days.',
        ], $items['data_subject_rights']['body']);
        $this->assertSame([
            'You can complain to the Data Protection Authority of Sri Lanka if you believe your data has been handled unlawfully.',
        ], $items['complaint']['body']);
    }

    protected function assertDisclosureItems(TestResponse $response): void
    {
        $items = $this->itemsOf($response);

        $this->assertSame([
            'Lesily stores and processes your data in Singapore.',
        ], $items['cross_border_transfer']['body']);
        $this->assertSame([
            "Most of this data is needed to run your account under the company's contract; without it you cannot use Lesily.",
            'Some records are kept because the law requires them.',
        ], $items['statutory_or_contractual']['body']);
        $this->assertSame([
            'No decision about you is made solely by automated means, and no profiling takes place.',
        ], $items['automated_decisions']['body']);
    }

    protected function assertOnlyThisCompanyAppears(TestResponse $response): void
    {
        $this->assertSame(
            'Contoso (Pvt) Ltd, BR number PV-00012345, Sri Lanka',
            $this->itemsOf($response)['controller']['body'][0],
        );
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('Fabrikam', $body);
        $this->assertStringNotContainsString('PV-00099999', $body);
    }

    /**
     * The notice's items keyed by their key.
     *
     * @return array<string, array{key: string, heading: string, body: list<string>}>
     */
    protected function itemsOf(TestResponse $response): array
    {
        $response->assertOk();

        return array_column($response->json('data'), null, 'key');
    }
}
