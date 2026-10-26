<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use App\Consent\Events\ConsentRecorded;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Auth\UseCases\RegistrationUniquenessGuard;
use App\Foundation\Auth\Events\UserMembershipEmergencyContactSet;
use App\Foundation\Auth\Exceptions\RegistrationConflictException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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
        $this->owner->forceFill(['default_company_id' => $this->companyA->getKey()])->save();
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
            'missing name' => [['name' => ''], 'name', 'The company name field is required.'],
            'over-long name' => [['name' => str_repeat('a', 256)], 'name', 'The company name field must not be greater than 255 characters.'],
            'missing BR number' => [['brNumber' => ''], 'brNumber', 'The BR (Business Registration) number is required.'],
            'BR number in the wrong format' => [['brNumber' => 'PVABC'], 'brNumber', 'Invalid BR number format.'],
            'missing EPF number' => [['epfNumber' => ''], 'epfNumber', 'The EPF employer number is required.'],
            'EPF number in the wrong format' => [['epfNumber' => 'AB/12345'], 'epfNumber', 'Invalid EPF number format.'],
            'missing country' => [['country' => ''], 'country', 'The country field is required.'],
            'country beyond two characters' => [['country' => 'LKA'], 'country', 'The country field must be 2 characters.'],
            'missing emergency contact name' => [['emergencyContactName' => ''], 'emergencyContactName', 'The emergency contact name field is required.'],
            'emergency contact email not an email' => [['emergencyContactEmail' => 'john-at-fabrikam'], 'emergencyContactEmail', 'The emergency contact email must be a valid email address.'],
            'phone not a number' => [['emergencyContactPhone' => '12345'], 'emergencyContactPhone', 'The emergency contact phone must be a valid phone number.'],
            'relationship not on the list' => [['emergencyContactRelationship' => 'cousin'], 'emergencyContactRelationship', 'The selected emergency contact relationship is invalid.'],
            'terms null' => [['acceptedTos' => null], 'acceptedTos', 'You must agree to the Terms of Service to continue.'],
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

    #[Test]
    public function when_the_country_is_not_supported_then_it_is_rejected_and_nothing_is_created(): void
    {
        // Given a signed-in owner choosing a country with no seeded defaults

        // When they add a company
        $response = $this->addCompany(['country' => 'ZZ']);

        // Then it is rejected and the half-opened company is rolled back
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::UnsupportedCountry);
        $response->assertJsonPath('message', 'Selected country is not supported.');
        $this->assertNoCompanyAdded();
    }

    #[Test]
    public function when_a_unique_violation_cannot_be_mapped_then_the_original_error_is_not_masked(): void
    {
        // Given a signed-in owner reusing their first company's BR number, and a
        // translator that maps no index
        $this->app->instance(RegistrationUniquenessGuard::class, new class extends RegistrationUniquenessGuard
        {
            public function conflictFor(UniqueConstraintViolationException $violation): ?RegistrationConflictException
            {
                return null;
            }
        });

        // When they add a company
        $response = $this->addCompany(['brNumber' => self::COMPANY_A_BR]);

        // Then the original database fault surfaces, never a masked conflict
        $response->assertInternalServerError();
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');
        $this->assertNoCompanyAdded();
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

    #[Test]
    public function when_the_request_names_another_owner_then_the_signed_in_person_still_owns_the_company(): void
    {
        // Given a signed-in owner whose request names someone else as the owner
        $someoneElse = User::factory()->create();

        // When they add a company
        $response = $this->addCompany(['ownerUserId' => $someoneElse->getKey()]);

        // Then the signed-in person owns it, and nothing is opened for the other user
        $companyId = $response->json('data.id');
        $this->assertIsString($companyId);
        $this->assertOwnedOnItsOwnTrial($companyId);
        $this->assertDatabaseMissing('company_user', ['user_id' => $someoneElse->getKey()]);
    }

    #[Test]
    public function when_the_owner_has_not_verified_their_email_then_the_company_is_still_added(): void
    {
        // Given a signed-in owner who has not verified their email yet
        $this->owner->forceFill(['email_verified_at' => null])->save();

        // When they add a company
        $response = $this->addCompany();

        // Then it is added — verification blocks nothing
        $response->assertCreated();
        $this->assertSame(2, Company::query()->where('owner_id', $this->owner->getKey())->count());
    }

    #[Test]
    public function when_an_owner_adds_another_company_then_it_opens_on_its_own_trial_with_its_contact_and_consent(): void
    {
        // Given a signed-in owner of Contoso, whose contact there is Kamala Perera

        // When they add Fabrikam with John Silva as its contact
        $response = $this->addCompany();

        // Then Fabrikam opens on its own trial, owned by them, with its own contact
        // and consent filed under it, and nothing else about them changes
        $companyId = $response->json('data.id');
        $this->assertIsString($companyId);
        $this->assertCompanyAddedOnTrial($response);
        $this->assertOwnedOnItsOwnTrial($companyId);
        $this->assertContactKeptPerCompany($companyId);
        $this->assertConsentRecordedFor($companyId);
        $this->assertNothingElseChanged();
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

    protected function assertCompanyAddedOnTrial(TestResponse $response): void
    {
        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Fabrikam');
        $response->assertJsonPath('data.status', 'trial');
        $response->assertJsonPath('data.plan', null);
    }

    protected function assertOwnedOnItsOwnTrial(string $companyId): void
    {
        $this->assertDatabaseHas('companies', [
            'id' => $companyId,
            'owner_id' => $this->owner->getKey(),
            'trial_ends_at' => '2026-07-08 00:00:00',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $companyId,
            'status' => 'trial',
            'trial_ends_at' => '2026-07-08 00:00:00',
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $companyId,
            'user_id' => $this->owner->getKey(),
            'role_id' => $this->seededRole('owner')->getKey(),
        ]);
    }

    protected function assertContactKeptPerCompany(string $companyId): void
    {
        $this->assertSame(
            ['John Silva', 'john@fabrikam.lk', '+94712345678', 'parent'],
            $this->ownerContactFor($companyId),
        );
        $this->assertSame('Kamala Perera', $this->ownerContactFor($this->companyA->getKey())[0]);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->whereForCompany($companyId)
            ->where('event_class', UserMembershipEmergencyContactSet::class)
            ->count());
    }

    /**
     * The owner's contact for the company as name, email, phone, relationship —
     * read through CompanyUser so its casts decrypt it.
     *
     * @return array{?string, ?string, ?string, ?string}
     */
    protected function ownerContactFor(string $companyId): array
    {
        $membership = CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $this->owner->getKey())
            ->sole();

        return [
            $membership->emergency_contact_name,
            $membership->emergency_contact_email,
            $membership->emergency_contact_phone,
            $membership->emergency_contact_relationship,
        ];
    }

    protected function assertConsentRecordedFor(string $companyId): void
    {
        $this->assertSame(2, LesilyStoredEvent::query()
            ->whereForCompany($companyId)
            ->where('event_class', ConsentRecorded::class)
            ->count());

        foreach (['tos', 'privacy'] as $policyType) {
            $consent = LesilyStoredEvent::query()
                ->whereForCompany($companyId)
                ->where('event_class', ConsentRecorded::class)
                ->where('event_properties->policyType', $policyType)
                ->sole();

            $this->assertSame('v1.0', $consent->event_properties['version']);
            $this->assertSame($this->owner->getKey(), $consent->event_properties['userId']);
            $this->assertSame('127.0.0.1', $consent->event_properties['ip']);
        }
    }

    protected function assertNothingElseChanged(): void
    {
        $this->assertSame($this->companyA->getKey(), $this->owner->refresh()->default_company_id);
        Notification::assertNothingSent();
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
