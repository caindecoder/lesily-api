<?php

namespace Tests\Feature\Companies;

use Illuminate\Support\Str;
use App\Companies\Aggregates\Company;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Exceptions\UnsupportedCountryException;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Companies\Exceptions\CompanyAlreadyExistsException;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * Create-company genesis at the domain seam (conv-plan §2.14 Rule C): register
 * mints fresh UUIDs, so the re-genesis and unsupported-country guards below are
 * unreachable via HTTP — they protect future callers (UC-1-031 additional-company
 * path) and replay integrity, and are provable only at this seam.
 */
class CreateCompanyTest extends CompaniesTestCase
{
    use CreatesTrialCompanies;
    use RefreshDatabase;

    // Sad — genesis is illegal from any state but [*]: an existing company cannot
    // be re-created, and the rejection must leave the original genesis untouched.
    #[Test]
    public function when_a_company_that_already_exists_is_created_again_then_it_is_rejected(): void
    {
        // Given a company that was already created
        $companyId = (string) Str::uuid();
        $this->createCompanyOnTrial($companyId);

        // When the same company is created again
        try {
            Company::retrieve($companyId)->create($this->buildCreateCompanyCommand($companyId, 'Contoso Again'));
            $this->fail('Re-creating an existing company must be rejected.');
        } catch (CompanyAlreadyExistsException) {
            // Then it is rejected and the original genesis is untouched
            $this->assertSame(2, LesilyStoredEvent::query()->whereForCompany($companyId)->count());
        }
    }

    // Sad — only launch-market countries have defaults to seed: an unsupported
    // country is rejected at the aggregate seam (the HTTP 422 country rule is the
    // slice-05 guard above it), and the rejection must persist nothing.
    #[Test]
    public function when_a_company_is_created_for_an_unsupported_country_then_it_is_rejected(): void
    {
        // Given an owner-signup command for a country without seeded defaults
        $companyId = (string) Str::uuid();
        $command = $this->buildCreateCompanyCommandForCountry($companyId, 'US');

        // When the company is created
        try {
            Company::retrieve($companyId)->create($command);
            $this->fail('Creating a company for an unsupported country must be rejected.');
        } catch (UnsupportedCountryException) {
            // Then it is rejected and nothing is persisted for the company
            $this->assertSame(0, LesilyStoredEvent::query()->whereForCompany($companyId)->count());
        }
    }

    protected function buildCreateCompanyCommandForCountry(string $companyId, string $country): CreateCompanyCommand
    {
        return CreateCompanyCommand::validateAndCreate(
            ['country' => $country] + $this->buildCreateCompanyCommand($companyId)->toArray()
        );
    }

    // Sad — the command rejects shape-invalid input at the ::validateAndCreate
    // seam. The max:255 bounds are the poison-event guard (an over-long value
    // would record a CompanyCreated whose projection can never succeed); the
    // required-field rows live at the registration HTTP seam
    // (RegisterOwnerSadPathTest), and BR/EPF uniqueness is its 409 guard.
    #[Test]
    #[DataProvider('provideInvalidRegistrationInput')]
    public function when_a_create_company_command_is_built_with_invalid_input_then_it_is_rejected(
        string $field,
        string $value,
    ): void {
        // Given a valid payload with one field invalidated
        $input = array_merge(self::validInput(), [$field => $value]);

        // When the command is built / Then validation rejects it
        $this->expectException(ValidationException::class);

        CreateCompanyCommand::validateAndCreate($input);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideInvalidRegistrationInput(): array
    {
        return [
            'name longer than its column' => ['name', str_repeat('a', 256)],
            'brNumber longer than its column' => ['brNumber', str_repeat('b', 256)],
            'epfNumber longer than its column' => ['epfNumber', str_repeat('e', 256)],
            'country longer than 2 chars' => ['country', 'LKA'],
            'ownerUserId not a uuid' => ['ownerUserId', 'not-a-uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function validInput(): array
    {
        return [
            'name' => 'Contoso',
            'brNumber' => 'PV-12345678',
            'epfNumber' => 'A-1234567',
            'country' => 'LK',
            'ownerUserId' => '019eb10c-0000-7000-8000-000000000000',
        ];
    }
}
