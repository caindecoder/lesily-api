<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

class ViewDsarRequestTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test]
    public function when_a_guest_views_a_request_then_they_are_refused_as_unauthenticated(): void
    {
        // Given
        $request = DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();

        // When
        $response = $this->viewDsarRequestAsGuest($request->getKey());

        // Then
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideUnknownRequestIds')]
    public function when_the_request_id_is_unknown_then_it_is_not_found(string $unknownId): void
    {
        // Given a member with a request of their own
        $member = $this->aCompanyMember($this->company);
        DsarRequestRecord::factory()->forSubject($this->company, $member)->create();

        // When
        $response = $this->viewDsarRequest($member, $this->company, $unknownId);

        // Then
        $this->assertHiddenAsNotFound($response, $unknownId);
    }

    #[Test]
    #[DataProvider('provideOtherCompanyViewers')]
    public function when_the_request_belongs_to_another_company_then_it_is_not_found(string $viewerKind): void
    {
        // Given a member of both companies whose request lives in the other one
        $subject = $this->aCompanyMember($this->company);
        $otherCompany = Company::factory()->create();
        $otherCompany->users()->attach($subject, ['role_id' => $this->seededRole('employee')->getKey()]);
        $request = DsarRequestRecord::factory()->forSubject($otherCompany, $subject)->create();
        $viewer = $viewerKind === 'owner' ? $this->aCompanyMember($this->company, 'owner') : $subject;

        // When
        $response = $this->viewDsarRequest($viewer, $this->company, $request->getKey());

        // Then
        $this->assertHiddenAsNotFound($response, $request->getKey());
    }

    #[Test]
    #[DataProvider('provideRolesWithoutViewAll')]
    public function when_a_member_without_view_all_views_another_members_request_then_it_is_not_found(string $roleCode): void
    {
        // Given
        $member = $this->aCompanyMember($this->company, $roleCode);
        $request = DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();

        // When
        $response = $this->viewDsarRequest($member, $this->company, $request->getKey());

        // Then
        $this->assertHiddenAsNotFound($response, $request->getKey());
    }

    #[Test]
    public function when_the_requester_no_longer_holds_view_all_then_they_still_see_the_request_they_made(): void
    {
        // Given a former admin, now an employee, who requested an export for a member
        $formerAdmin = $this->aCompanyMember($this->company);
        $request = DsarRequestRecord::factory()
            ->forSubject($this->company, $this->aCompanyMember($this->company))
            ->requestedBy($formerAdmin, 'admin')
            ->create();

        // When
        $response = $this->viewDsarRequest($formerAdmin, $this->company, $request->getKey());

        // Then
        $this->assertDsarRequestShown($response, $request, false);
    }

    #[Test]
    public function when_a_request_was_made_on_the_subjects_behalf_then_the_subject_sees_it(): void
    {
        // Given an admin requested an export for the subject
        $subject = $this->aCompanyMember($this->company);
        $request = DsarRequestRecord::factory()
            ->forSubject($this->company, $subject)
            ->requestedBy($this->aCompanyMember($this->company, 'admin'), 'admin')
            ->create();

        // When
        $response = $this->viewDsarRequest($subject, $this->company, $request->getKey());

        // Then
        $this->assertDsarRequestShown($response, $request, false);
    }

    #[Test]
    #[DataProvider('provideRolesWithViewAll')]
    public function when_a_view_all_holder_views_any_company_request_then_it_is_shown(string $roleCode): void
    {
        // Given
        $holder = $this->aCompanyMember($this->company, $roleCode);
        $request = DsarRequestRecord::factory()->forSubject($this->company, $this->aCompanyMember($this->company))->create();

        // When
        $response = $this->viewDsarRequest($holder, $this->company, $request->getKey());

        // Then
        $this->assertDsarRequestShown($response, $request, false);
    }

    #[Test]
    public function when_the_subject_views_their_fulfilled_request_then_the_sla_dates_are_shown(): void
    {
        // Given a request made 28 days ago and fulfilled today
        $subject = $this->aCompanyMember($this->company);
        $request = DsarRequestRecord::factory()->forSubject($this->company, $subject)->requestedDaysAgo(28)->fulfilled()->create();

        // When
        $response = $this->viewDsarRequest($subject, $this->company, $request->getKey());

        // Then
        $this->assertDsarRequestShown($response, $request, true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideOtherCompanyViewers(): array
    {
        return [
            'this company owner' => ['owner'],
            'the subject from their other company' => ['subject'],
        ];
    }
}
