<?php

namespace Tests\Feature\Companies;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Companies\UseCases\Commands\CreateCompanyCommand;

class CreateCompanyCommandTest extends CompaniesTestCase
{
    // Sad — the command rejects shape-invalid input at construction (the
    // ::validateAndCreate seam). BR/EPF uniqueness is a cross-record guard and
    // belongs to the registration HTTP seam (slice increment 05), not here.
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
            'missing name' => ['name', ''],
            'missing brNumber' => ['brNumber', ''],
            'missing epfNumber' => ['epfNumber', ''],
            'country not an ISO alpha-2 code' => ['country', 'LKA'],
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
