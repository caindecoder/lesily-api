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

    #[Test]
    #[DataProvider('provideRolesWithoutRequestForOthers')]
    public function when_a_member_without_the_permission_requests_for_another_member_then_it_is_refused(string $roleCode): void
    {
        // Given
        $company = Company::factory()->create();
        $actor = $this->aCompanyMember($company, $roleCode);
        $colleague = $this->aCompanyMember($company);

        // When
        $response = $this->submitDsarRequest($actor, $company, ['subject_user_id' => $colleague->getKey()]);

        // Then
        $this->assertRoleInsufficient($response, 'dsar.request-for-others');
        $this->assertNoDsarRequestRecorded($company);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideRolesWithoutRequestForOthers(): array
    {
        return [
            'employee' => ['employee'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'auditor' => ['auditor'],
        ];
    }

    #[Test]
    #[DataProvider('provideNonMemberSubjects')]
    public function when_the_subject_is_not_a_member_of_the_company_then_it_is_refused_as_not_found(string $nonMember): void
    {
        // Given
        $company = Company::factory()->create();
        $admin = $this->aCompanyMember($company, 'admin');
        $subjectUserId = $this->aNonMemberSubject($nonMember, $company);

        // When
        $response = $this->submitDsarRequest($admin, $company, ['subject_user_id' => $subjectUserId]);

        // Then
        $this->assertResourceNotFound($response);
        $this->assertNoDsarRequestRecorded($company);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideNonMemberSubjects(): array
    {
        return [
            "another company's member" => ['other-company-member'],
            'a removed member' => ['removed-member'],
            'an unknown user' => ['unknown-user'],
        ];
    }

    #[Test]
    #[DataProvider('provideRepeatRequesters')]
    public function when_an_open_request_exists_for_the_subject_then_another_is_refused_as_in_progress(string $repeatRequester): void
    {
        // Given
        $company = Company::factory()->create();
        $subject = $this->aCompanyMember($company);
        $this->submitDsarRequest($subject, $company);
        [$actor, $body] = $this->aRepeatRequestFor($subject, $company, $repeatRequester);

        // When
        $response = $this->submitDsarRequest($actor, $company, $body);

        // Then
        $this->assertRefusedAsInProgress($response);
        $this->assertOneDsarRequestRecorded($company, $subject);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideRepeatRequesters(): array
    {
        return [
            'the subject again' => ['subject'],
            'an admin on their behalf' => ['admin'],
        ];
    }
}
