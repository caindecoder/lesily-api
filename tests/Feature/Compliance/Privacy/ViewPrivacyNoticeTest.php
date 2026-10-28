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
}
