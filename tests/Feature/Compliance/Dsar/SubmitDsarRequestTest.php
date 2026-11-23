<?php

namespace Tests\Feature\Compliance\Dsar;

use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
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
        $this->idempotencyKey = null;

        // When
        $response = $this->submitDsarRequest($this->employee, $this->company);

        // Then
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::IdempotencyKeyRequired);
        $this->assertNoDsarRequestRecorded();
    }

    #[Test]
    #[DataProvider('provideMalformedSubjectReferences')]
    public function when_the_subject_reference_is_malformed_then_the_request_is_refused_as_invalid(mixed $subjectReference): void
    {
        // Given
        $body = ['subjectUserId' => $subjectReference];

        // When
        $response = $this->submitDsarRequest($this->employee, $this->company, $body);

        // Then
        $this->assertValidationRejected($response, 'subjectUserId', 'The subject user id field must be a valid UUID.');
        $this->assertNoDsarRequestRecorded();
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
        $actor = $this->memberWithRole($roleCode);
        $colleague = $this->aCompanyMember($this->company);

        // When
        $response = $this->submitDsarRequest($actor, $this->company, ['subjectUserId' => $colleague->getKey()]);

        // Then
        $this->assertRoleInsufficient($response, 'dsar.request-for-others');
        $this->assertNoDsarRequestRecorded();
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
        $subjectUserId = $this->aNonMemberSubject($nonMember, $this->company);

        // When
        $response = $this->submitDsarRequest($this->admin, $this->company, ['subjectUserId' => $subjectUserId]);

        // Then
        $this->assertResourceNotFound($response);
        $this->assertNoDsarRequestRecorded();
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
        $subject = $this->employee;
        $this->submitDsarRequest($subject, $this->company);
        [$actor, $body] = $this->aRepeatRequestFor($subject, $repeatRequester);

        // When
        $response = $this->submitDsarRequest($actor, $this->company, $body);

        // Then
        $this->assertRefusedAsInProgress($response);
        $this->assertOneDsarRequestRecorded($this->company, $subject);
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS + 1);
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
        $subject = $this->employee;
        DsarRequestRecord::factory()->forSubject($this->company, $subject)->fulfilled()->create();

        // When
        $response = $this->submitDsarRequest($subject, $this->company);

        // Then
        $this->assertAcceptedAsTheOnlyOpenRequest($response, $this->company, $subject);
    }

    #[Test]
    public function when_the_subject_has_an_open_request_in_another_company_then_a_request_here_is_accepted(): void
    {
        // Given
        $subject = $this->employee;
        $otherCompany = Company::factory()->create();
        $this->attachMember($otherCompany, $subject, $this->seededRole('employee'));
        DsarRequestRecord::factory()->forSubject($otherCompany, $subject)->create();

        // When
        $response = $this->submitDsarRequest($subject, $this->company);

        // Then
        $this->assertAcceptedAsTheOnlyOpenRequest($response, $this->company, $subject);
    }

    #[Test]
    public function when_a_member_who_never_accepted_the_policies_requests_their_data_then_it_is_recorded(): void
    {
        // Given a member who has accepted neither the terms nor the privacy policy here
        $member = $this->aMemberWhoNeverConsented($this->company);

        // When
        $response = $this->submitDsarRequest($member, $this->company);

        // Then
        $this->assertDsarRequestRecorded($response, $member, $member);
    }

    #[Test]
    public function when_the_same_idempotency_key_is_replayed_then_one_request_is_recorded(): void
    {
        // Given
        $subject = $this->employee;
        $first = $this->submitDsarRequest($subject, $this->company);

        // When
        $replay = $this->submitDsarRequest($subject, $this->company);

        // Then
        $this->assertReplayedOf($replay, $first);
        $this->assertOneDsarRequestRecorded($this->company, $subject);
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS + 1);
    }

    #[Test]
    public function when_a_member_names_themselves_as_the_subject_then_it_is_their_own_request(): void
    {
        // Given
        $body = ['subjectUserId' => $this->employee->getKey()];

        // When
        $response = $this->submitDsarRequest($this->employee, $this->company, $body);

        // Then
        $this->assertDsarRequestRecorded($response, $this->employee, $this->employee);
    }

    #[Test]
    #[DataProvider('provideEveryRole')]
    public function when_a_member_requests_their_own_data_then_the_request_is_recorded_in_progress(string $roleCode): void
    {
        // Given
        $member = $this->memberWithRole($roleCode);

        // When
        $response = $this->submitDsarRequest($member, $this->company);

        // Then
        $this->assertDsarRequestRecorded($response, $member, $member);
        $this->assertDsarRequestAudited($response, $member, $member);
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS + 1);
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
        $actor = $this->memberWithRole($roleCode);

        // When
        $response = $this->submitDsarRequest($actor, $this->company, ['subjectUserId' => $this->employee->getKey()]);

        // Then
        $this->assertDsarRequestRecorded($response, $this->employee, $actor);
        $this->assertDsarRequestAudited($response, $this->employee, $actor);
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS + 1);
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
        $member = $this->employee;

        // When
        $response = $this->submitDsarRequest($member, $this->company);
        $this->runQueuedHandlersFor(DsarRequestSubmitted::class);

        // Then
        $this->assertExportQueuedFor($this->requestFrom($response));
    }
}
