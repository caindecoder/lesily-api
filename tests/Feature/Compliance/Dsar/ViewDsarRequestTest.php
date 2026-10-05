<?php

namespace Tests\Feature\Compliance\Dsar;

use Illuminate\Support\Str;
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

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnknownRequestIds(): array
    {
        return [
            'random uuid' => [(string) Str::uuid()],
            'malformed text' => ['not-a-request-id'],
            'number' => ['12345'],
        ];
    }
}
