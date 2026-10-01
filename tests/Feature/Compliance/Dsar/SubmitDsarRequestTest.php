<?php

namespace Tests\Feature\Compliance\Dsar;

use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class SubmitDsarRequestTest extends DsarTestCase
{
    #[Test]
    public function when_the_request_has_no_token_then_it_is_unauthenticated(): void
    {
        // Given
        $body = [];

        // When
        $response = $this->submitDsarRequestAsGuest($body);

        // Then
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_the_idempotency_key_is_missing_then_the_request_is_refused(): void
    {
        // Given
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company);
        $this->idempotencyKey = null;

        // When
        $response = $this->submitDsarRequest($member, $company);

        // Then
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::IdempotencyKeyRequired);
        $this->assertNoDsarRequestRecorded($company);
    }

    #[Test]
    #[DataProvider('provideMalformedSubjectReferences')]
    public function when_the_subject_reference_is_malformed_then_the_request_is_refused_as_invalid(mixed $subjectReference): void
    {
        // Given
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company);

        // When
        $response = $this->submitDsarRequest($member, $company, ['subject_user_id' => $subjectReference]);

        // Then
        $this->assertValidationRejected($response, 'subject_user_id', 'The subject user id field must be a valid UUID.');
        $this->assertNoDsarRequestRecorded($company);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideMalformedSubjectReferences(): array
    {
        return [
            'not a uuid' => ['not-a-uuid'],
            'a number' => [42],
        ];
    }
}
