<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;

/**
 * UC-1-031 "second company" — POST /api/v1/companies: a signed-in person opens
 * another company with its own owner row, emergency contact, consent and trial.
 */
class AddCompanyTest extends CompaniesTestCase
{
    protected const string COMPANY_A_BR = 'PV-12345678';

    protected const string COMPANY_A_EPF = 'A-123456';

    protected User $owner;

    protected Company $companyA;

    protected ?string $idempotencyKey;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seedRbacCatalog();
        $this->travelTo('2026-06-08 00:00:00');
        config(['app.debug' => false]);

        $this->owner = User::factory()->create();
        $this->companyA = Company::factory()
            ->registeredAs(self::COMPANY_A_BR, self::COMPANY_A_EPF)
            ->named('Contoso')
            ->ownedBy($this->owner)
            ->create();
        $this->enrolOwnerWithContact($this->companyA, $this->owner);
        $this->idempotencyKey = (string) Str::uuid();
    }

    #[Test]
    public function when_a_guest_adds_a_company_then_they_are_refused(): void
    {
        // Given no one is signed in

        // When a guest adds a company
        $response = $this->addCompanyAsGuest();

        // Then they are refused and no company is added
        $this->assertUnauthenticated($response);
        $this->assertNoCompanyAdded();
    }

    protected function enrolOwnerWithContact(Company $company, User $owner): void
    {
        $company->users()->attach($owner, [
            'role_id' => $this->seededRole('owner')->getKey(),
            'emergency_contact_name' => 'Kamala Perera',
            'emergency_contact_email' => 'kamala@contoso.lk',
            'emergency_contact_phone' => '+94771234567',
            'emergency_contact_relationship' => 'spouse',
        ]);
    }

    protected function addCompanyAsGuest(): TestResponse
    {
        return $this->postJson('/api/v1/companies', $this->validCompanyPayload(), ['Idempotency-Key' => $this->idempotencyKey]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validCompanyPayload(): array
    {
        return [
            'name' => 'Fabrikam',
            'brNumber' => 'PV-99999999',
            'epfNumber' => 'A-999999',
            'country' => 'LK',
            'emergencyContactName' => 'John Silva',
            'emergencyContactEmail' => 'john@fabrikam.lk',
            'emergencyContactPhone' => '+94712345678',
            'emergencyContactRelationship' => 'parent',
            'acceptedTos' => true,
        ];
    }

    protected function assertNoCompanyAdded(): void
    {
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('company_user', 1);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('stored_events', 0);
    }
}
