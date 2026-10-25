<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

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

    #[Test]
    public function when_no_idempotency_key_is_sent_then_the_company_is_refused(): void
    {
        // Given a signed-in owner whose request carries no idempotency key
        $this->idempotencyKey = null;

        // When they add a company
        $response = $this->addCompany();

        // Then it is refused before any work and no company is added
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::IdempotencyKeyRequired);
        $this->assertNoCompanyAdded();
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[Test]
    #[DataProvider('provideInvalidCompanyDetails')]
    public function when_the_company_details_are_invalid_then_they_are_rejected_and_nothing_is_created(array $override, string $invalidField, string $expectedMessage): void
    {
        // Given a signed-in owner submitting one invalid field

        // When they add a company
        $response = $this->addCompany($override);

        // Then the field is rejected with its message and no company is added
        $this->assertValidationRejected($response, $invalidField, $expectedMessage);
        $this->assertNoCompanyAdded();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function provideInvalidCompanyDetails(): array
    {
        return [
            'missing name' => [['name' => ''], 'name', 'The name field is required.'],
            'missing BR number' => [['brNumber' => ''], 'brNumber', 'The BR (Business Registration) number is required.'],
            'missing EPF number' => [['epfNumber' => ''], 'epfNumber', 'The EPF employer number is required.'],
            'missing country' => [['country' => ''], 'country', 'The country field is required.'],
            'missing emergency contact name' => [['emergencyContactName' => ''], 'emergencyContactName', 'The emergency contact name field is required.'],
            'emergency contact email not an email' => [['emergencyContactEmail' => 'john-at-fabrikam'], 'emergencyContactEmail', 'The emergency contact email must be a valid email address.'],
            'phone not a number' => [['emergencyContactPhone' => '12345'], 'emergencyContactPhone', 'The emergency contact phone must be a valid phone number.'],
            'relationship not on the list' => [['emergencyContactRelationship' => 'cousin'], 'emergencyContactRelationship', 'The selected emergency contact relationship is invalid.'],
            'terms not accepted' => [['acceptedTos' => false], 'acceptedTos', 'You must agree to the Terms of Service to continue.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[Test]
    #[DataProvider('provideTakenRegistrationNumbers')]
    public function when_the_br_or_epf_number_is_already_registered_then_it_conflicts_and_nothing_is_created(array $override, ApiErrorCode $expectedCode, string $expectedMessage): void
    {
        // Given a signed-in owner reusing a number their first company holds

        // When they add a company
        $response = $this->addCompany($override);

        // Then it conflicts on that number and no company is added
        $this->assertConflictReported($response, $expectedCode, $expectedMessage);
        $this->assertNoCompanyAdded();
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: ApiErrorCode, 2: string}>
     */
    public static function provideTakenRegistrationNumbers(): array
    {
        return [
            'business registration number' => [['brNumber' => self::COMPANY_A_BR], ApiErrorCode::BrNumberRegistered, 'This BR number is already registered.'],
            'employer provident fund number' => [['epfNumber' => self::COMPANY_A_EPF], ApiErrorCode::EpfNumberRegistered, 'This EPF number is already registered.'],
        ];
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

    /**
     * @param  array<string, mixed>  $override
     */
    protected function addCompany(array $override = []): TestResponse
    {
        Sanctum::actingAs($this->owner);

        $headers = $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];

        return $this->postJson('/api/v1/companies', [...$this->validCompanyPayload(), ...$override], $headers);
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

    #[Test]
    public function when_the_client_ip_is_undeterminable_then_the_company_is_rejected_as_invalid(): void
    {
        // Given a signed-in owner whose request carries no resolvable client IP
        $this->withServerVariables(['REMOTE_ADDR' => '']);

        // When they add a company
        $response = $this->addCompany();

        // Then it is rejected as invalid and no company is added
        $this->assertValidationRejected($response, 'ip', 'The ip field is required.');
        $this->assertNoCompanyAdded();
    }

    #[Test]
    public function when_both_the_br_and_epf_numbers_are_taken_then_the_br_conflict_is_reported(): void
    {
        // Given a signed-in owner reusing both numbers their first company holds

        // When they add a company
        $response = $this->addCompany(['brNumber' => self::COMPANY_A_BR, 'epfNumber' => self::COMPANY_A_EPF]);

        // Then the BR conflict is the one reported and no company is added
        $this->assertConflictReported($response, ApiErrorCode::BrNumberRegistered, 'This BR number is already registered.');
        $this->assertNoCompanyAdded();
    }

    #[Test]
    public function when_another_company_already_has_the_name_then_the_company_is_still_added(): void
    {
        // Given a signed-in owner whose first company is named Contoso

        // When they add a company with the same name
        $response = $this->addCompany(['name' => 'Contoso']);

        // Then the company is still added beside the first
        $response->assertCreated();
        $this->assertSame(2, Company::query()->where('name', 'Contoso')->count());
    }

    protected function assertConflictReported(TestResponse $response, ApiErrorCode $code, string $message): void
    {
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, $code);
        $response->assertJsonPath('message', $message);
    }

    protected function assertNoCompanyAdded(): void
    {
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('company_user', 1);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('stored_events', 0);
    }
}
