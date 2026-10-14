<?php

namespace Tests\Feature\Compliance\Dsar;

use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Billing\Enums\BillingPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;

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
        $response = $this->submitDsarRequest($member, $company, ['subjectUserId' => $subjectReference]);

        // Then
        $this->assertValidationRejected($response, 'subjectUserId', 'The subject user id field must be a valid UUID.');
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
        $response = $this->submitDsarRequest($actor, $company, ['subjectUserId' => $colleague->getKey()]);

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
        $response = $this->submitDsarRequest($admin, $company, ['subjectUserId' => $subjectUserId]);

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

    #[Test]
    public function when_only_a_closed_request_exists_for_the_subject_then_a_new_request_is_accepted(): void
    {
        // Given
        $company = Company::factory()->create();
        $subject = $this->aCompanyMember($company);
        DsarRequestRecord::factory()->forSubject($company, $subject)->fulfilled()->create();

        // When
        $response = $this->submitDsarRequest($subject, $company);

        // Then
        $this->assertAcceptedAsTheOnlyOpenRequest($response, $company, $subject);
    }

    #[Test]
    public function when_the_subject_has_an_open_request_in_another_company_then_a_request_here_is_accepted(): void
    {
        // Given
        $company = Company::factory()->create();
        $subject = $this->aCompanyMember($company);
        $otherCompany = Company::factory()->create();
        $otherCompany->users()->attach($subject, ['role_id' => $this->seededRole('employee')->getKey()]);
        DsarRequestRecord::factory()->forSubject($otherCompany, $subject)->create();

        // When
        $response = $this->submitDsarRequest($subject, $company);

        // Then
        $this->assertAcceptedAsTheOnlyOpenRequest($response, $company, $subject);
    }

    #[Test]
    public function when_the_same_idempotency_key_is_replayed_then_one_request_is_recorded(): void
    {
        // Given
        $company = Company::factory()->create();
        $subject = $this->aCompanyMember($company);
        $first = $this->submitDsarRequest($subject, $company);

        // When
        $replay = $this->submitDsarRequest($subject, $company);

        // Then
        $this->assertReplayedOf($replay, $first);
        $this->assertOneDsarRequestRecorded($company, $subject);
    }

    #[Test]
    public function when_a_member_names_themselves_as_the_subject_then_it_is_their_own_request(): void
    {
        // Given
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company);

        // When
        $response = $this->submitDsarRequest($member, $company, ['subjectUserId' => $member->getKey()]);

        // Then
        $this->assertDsarRequestRecorded($response, $member, $member);
    }

    #[Test]
    #[DataProvider('provideEveryRole')]
    public function when_a_member_requests_their_own_data_then_the_request_is_recorded_in_progress(string $roleCode): void
    {
        // Given
        $company = Company::factory()->onPlan(BillingPlan::Free)->create();
        $member = $this->aCompanyMember($company, $roleCode);

        // When
        $response = $this->submitDsarRequest($member, $company);

        // Then
        $this->assertDsarRequestRecorded($response, $member, $member);
        $this->assertDsarRequestAudited($response, $member, $member);
    }

    /**
     * @return array<string, array{0: string}>
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
        ];
    }

    #[Test]
    #[DataProvider('provideRequestForOthersRoles')]
    public function when_an_owner_or_admin_requests_for_a_member_then_the_request_is_recorded_on_their_behalf(string $roleCode): void
    {
        // Given
        $company = Company::factory()->create();
        $actor = $this->aCompanyMember($company, $roleCode);
        $member = $this->aCompanyMember($company);

        // When
        $response = $this->submitDsarRequest($actor, $company, ['subjectUserId' => $member->getKey()]);

        // Then
        $this->assertDsarRequestRecorded($response, $member, $actor);
        $this->assertDsarRequestAudited($response, $member, $actor);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideRequestForOthersRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
        ];
    }

    #[Test]
    public function when_a_request_is_submitted_then_its_export_is_queued(): void
    {
        // Given
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company);

        // When
        $response = $this->submitDsarRequest($member, $company);
        $this->runQueuedHandlersFor(DsarRequestSubmitted::class);

        // Then
        $this->assertExportQueuedFor($this->requestFrom($response));
    }
}
