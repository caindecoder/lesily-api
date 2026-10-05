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
