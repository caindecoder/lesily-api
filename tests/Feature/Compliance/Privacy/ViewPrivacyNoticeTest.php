<?php

namespace Tests\Feature\Compliance\Privacy;

use App\Models\User;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
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
}
