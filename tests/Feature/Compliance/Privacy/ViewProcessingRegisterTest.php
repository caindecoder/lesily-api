<?php

namespace Tests\Feature\Compliance\Privacy;

use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
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
        // Given an owner of one company and a second company they never joined
        [, $outsider] = $this->aCompanyWithMember();
        [$otherCompany] = $this->aCompanyWithMember();

        // When they view the second company's processing register
        $response = $this->viewRegister($outsider, $otherCompany);

        // Then the company context is denied
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
    }

    #[Test]
    public function when_a_member_owes_a_new_policy_version_then_consent_is_required(): void
    {
        // Given an owner who accepted the policies, then a new privacy policy version is published
        [$company, $owner] = $this->aCompanyWithMember();
        config(['lesily.policies.privacy' => 'v1.1']);

        // When they view the processing register
        $response = $this->viewRegister($owner, $company);

        // Then the register is refused until they accept
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::ConsentRequired);
    }
}
