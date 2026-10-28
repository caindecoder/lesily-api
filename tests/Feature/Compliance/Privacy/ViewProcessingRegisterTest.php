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
}
