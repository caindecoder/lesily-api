<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use RuntimeException;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Auth\UseCases\RegistrationUniquenessGuard;
use App\Foundation\Auth\UseCases\Commands\RegisterOwnerCommand;
use App\Foundation\Auth\Exceptions\RegistrationConflictException;

class RegisterOwnerSadPathTest extends AuthTestCase
{
    use RefreshDatabase;

    private const EXISTING_EMAIL = 'existing@contoso.lk';

    private const EXISTING_BR = 'PV-99999999';

    private const EXISTING_EPF = 'A-9999999';

    protected function setUp(): void
    {
        parent::setUp();

        // Registration fires the welcome + verification reactors (genesis side
        // effects); spy them at the boundary so this suite stays about rejection.
        Notification::fake();

        $this->seedRbacCatalog();
        $this->travelTo('2026-06-08 00:00:00');
    }

    // Sad (validation) — a malformed payload is rejected before any aggregate runs.
    #[Test]
    #[DataProvider('invalidPayloadProvider')]
    public function when_the_registration_payload_is_invalid_then_it_is_rejected(array $override, string $invalidField): void
    {
        // Given an otherwise-valid signup with one invalid field
        $payload = [...$this->validRegistrationPayload(), ...$override];

        // When the guest submits it
        $response = $this->postJson('/api/v1/auth/register', $payload);

        // Then registration is rejected and the offending field is reported
        $this->assertValidationRejected($response, $invalidField);
    }

    /**
     * Each row blanks or corrupts one field of an otherwise-valid payload. The
     * weak-password + unaccepted-ToS rows are #05's new rules; the empty-field
     * rows are regression guards over the `required` rules established in #02/#04
     * (one per shape-validated field, so a dropped `required` can't survive).
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'weak password' => [['password' => 'short'], 'password'],
            'terms not accepted' => [['acceptedTos' => false], 'acceptedTos'],
            'missing name' => [['name' => ''], 'name'],
            'missing email' => [['email' => ''], 'email'],
            'missing company name' => [['companyName' => ''], 'companyName'],
            'missing BR number' => [['brNumber' => ''], 'brNumber'],
            'missing EPF number' => [['epfNumber' => ''], 'epfNumber'],
            'missing country' => [['country' => ''], 'country'],
            'missing emergency contact name' => [['emergencyContactName' => ''], 'emergencyContactName'],
            'missing emergency contact phone' => [['emergencyContactPhone' => ''], 'emergencyContactPhone'],
        ];
    }

    // Sad (conflict) — a globally-unique identifier already in use is rejected
    // with 409 (well-formed but conflicts with current state — api-conventions §6),
    // and nothing new is persisted.
    #[Test]
    #[DataProvider('duplicateFieldProvider')]
    public function when_a_registration_field_is_already_registered_then_it_conflicts(array $override, string $expectedCode): void
    {
        // Given an account already holds these identifiers
        $this->givenAnExistingOwnerAccount();
        $usersBefore = User::query()->count();
        $companiesBefore = Company::query()->count();

        // When a guest registers colliding on one of them
        $response = $this->postJson('/api/v1/auth/register', [...$this->validRegistrationPayload(), ...$override]);

        // Then the registration conflicts and creates nothing new
        $this->assertConflict($response, $expectedCode);
        $this->assertNoNewAccounts($usersBefore, $companiesBefore);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function duplicateFieldProvider(): array
    {
        return [
            'email' => [['email' => self::EXISTING_EMAIL], 'EMAIL_REGISTERED'],
            'business registration number' => [['brNumber' => self::EXISTING_BR], 'BR_NUMBER_REGISTERED'],
            'employer provident fund number' => [['epfNumber' => self::EXISTING_EPF], 'EPF_NUMBER_REGISTERED'],
        ];
    }

    protected function givenAnExistingOwnerAccount(): void
    {
        User::factory()->withEmail(self::EXISTING_EMAIL)->create();
        Company::factory()->registeredAs(self::EXISTING_BR, self::EXISTING_EPF)->create();
    }

    protected function assertConflict(TestResponse $response, string $expectedCode): void
    {
        $response->assertStatus(409);
        $response->assertJsonPath('error_code', $expectedCode);
        $response->assertJsonPath('errors', []);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
        $this->assertNotEmpty($response->json('correlation_id'));
    }

    protected function assertNoNewAccounts(int $usersBefore, int $companiesBefore): void
    {
        $this->assertSame($usersBefore, User::query()->count(), 'No user may be created on a conflict.');
        $this->assertSame($companiesBefore, Company::query()->count(), 'No company may be created on a conflict.');
        $this->assertDatabaseCount('stored_events', 0);
    }

    // Edge (race backstop) — two concurrent signups can both pass the pre-tx
    // check-then-act guard; the second to persist hits the DB UNIQUE constraint.
    // That backstop must surface the same field-specific 409, never a 500.
    #[Test]
    #[DataProvider('duplicateFieldProvider')]
    public function when_a_race_slips_past_the_uniqueness_guard_then_the_database_backstop_still_conflicts(array $override, string $expectedCode): void
    {
        // Given an account already holds these identifiers, and the pre-tx guard is
        // bypassed to reproduce the concurrent race that slips past check-then-act
        $this->givenAnExistingOwnerAccount();
        $this->bypassUniquenessGuard();
        $usersBefore = User::query()->count();
        $companiesBefore = Company::query()->count();

        // When a guest registers colliding on one of them
        $response = $this->postJson('/api/v1/auth/register', [...$this->validRegistrationPayload(), ...$override]);

        // Then the database backstop conflicts identically and creates nothing new
        $this->assertConflict($response, $expectedCode);
        $this->assertNoNewAccounts($usersBefore, $companiesBefore);
    }

    protected function bypassUniquenessGuard(): void
    {
        // Replace the pre-transaction check with a no-op so the request reaches the
        // DB UNIQUE constraint — the only honest single-process way to exercise the
        // race backstop, where the conflict is detected by the database, not the guard.
        $this->app->instance(RegistrationUniquenessGuard::class, new class extends RegistrationUniquenessGuard
        {
            public function ensureUnique(RegisterOwnerCommand $command): void {}
        });
    }

    // Edge (re-raise) — a unique violation the translator cannot map (an index
    // outside email/BR/EPF) must surface as the original 500, never be masked as a
    // registration 409. Bypassing the guard AND nulling the translation forces the
    // backstop down the unmapped path while a real duplicate still trips the index.
    #[Test]
    public function when_the_backstop_cannot_map_the_violation_then_the_original_error_is_not_masked(): void
    {
        // Given the pre-tx guard is bypassed and the translator maps nothing
        config(['app.debug' => false]);
        $this->givenAnExistingOwnerAccount();
        $this->app->instance(RegistrationUniquenessGuard::class, new class extends RegistrationUniquenessGuard
        {
            public function ensureUnique(RegisterOwnerCommand $command): void {}

            public function conflictFor(UniqueConstraintViolationException $violation): ?RegistrationConflictException
            {
                return null;
            }
        });
        $usersBefore = User::query()->count();
        $companiesBefore = Company::query()->count();

        // When a guest collides on email so the DB UNIQUE constraint genuinely fires
        $response = $this->postJson('/api/v1/auth/register', [...$this->validRegistrationPayload(), 'email' => self::EXISTING_EMAIL]);

        // Then the original database fault surfaces, never a masked conflict
        $response->assertStatus(500);
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');
        $this->assertNoNewAccounts($usersBefore, $companiesBefore);
    }

    // Edge (translation seam) — a unique violation on a constraint outside the
    // registration field set (email / BR / EPF) must NOT be disguised as a
    // registration conflict. This exercises the REAL guard's unmapped arm — the
    // 500 walk above stubs the translation, so it proves only the caller's re-raise.
    #[Test]
    public function when_a_unique_violation_is_on_an_unmapped_constraint_then_no_registration_conflict_is_translated(): void
    {
        // Given a unique violation on some constraint the guard does not map
        $violation = $this->uniqueViolationOnIndex('some_other_unique');

        // When the guard is asked to translate it into a registration conflict
        $conflict = (new RegistrationUniquenessGuard)->conflictFor($violation);

        // Then there is no conflict to raise — the caller re-throws the original error
        $this->assertNull($conflict);
    }

    protected function uniqueViolationOnIndex(string $index): UniqueConstraintViolationException
    {
        return (new UniqueConstraintViolationException(
            'mysql',
            'insert into `companies` (`x`) values (?)',
            ['x'],
            new RuntimeException("Duplicate entry 'x' for key '{$index}'"),
        ))->setIndex($index);
    }

    // Edge (atomic rollback) — an unsupported country passes shape validation but
    // throws mid-transaction (after the owner is written), proving the whole
    // registration rolls back as one unit.
    #[Test]
    public function when_an_unsupported_country_is_submitted_then_nothing_is_persisted(): void
    {
        // Given a signup for a country with no seeded defaults
        $payload = [...$this->validRegistrationPayload(), 'country' => 'ZZ'];

        // When the guest submits it
        $response = $this->postJson('/api/v1/auth/register', $payload);

        // Then the request is rejected and the half-built account is rolled back
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'UNSUPPORTED_COUNTRY');
        $this->assertNothingPersisted();
    }

    protected function assertNothingPersisted(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseCount('stored_events', 0);
    }

    // Edge (rate limit) — the public register endpoint runs under the auth-tier
    // per-IP limit (10/min) so credential-stuffing and signup abuse are throttled.
    #[Test]
    public function when_registration_attempts_exceed_the_auth_rate_limit_then_they_are_throttled(): void
    {
        // Given the per-IP auth quota for the minute is exhausted
        $this->exhaustAuthLimit(10, '/api/v1/auth/register');

        // When another registration is attempted
        $response = $this->postJson('/api/v1/auth/register', []);

        // Then it is throttled with retry guidance
        $this->assertThrottled($response);
    }

    // Edge (rate limit) — the limit is keyed per-IP, so one abusive client can't
    // throttle signups for everyone (the limiter is excluded from mutation testing,
    // so this behavioural test is the only guard on the ->by(ip) key).
    #[Test]
    public function when_a_separate_ip_registers_then_it_is_unaffected_by_another_ips_throttle(): void
    {
        // Given one IP has exhausted its auth quota for the minute
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);
        $this->exhaustAuthLimit(10, '/api/v1/auth/register');
        $this->postJson('/api/v1/auth/register', [])->assertStatus(429);

        // When a different IP makes its first attempt
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
            ->postJson('/api/v1/auth/register', []);

        // Then it is not throttled by the other IP's exhausted quota
        $response->assertStatus(422);
    }
}
