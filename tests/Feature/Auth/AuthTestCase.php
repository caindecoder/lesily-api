<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;

abstract class AuthTestCase extends TestCase
{
    /**
     * Canonical login-ready credentials, shared by the login and logout suites
     * (§2.10). A registered, verified user authenticates with these.
     */
    protected const LOGIN_EMAIL = 'alice@contoso.lk';

    protected const LOGIN_PASSWORD = 'Str0ng-Pass!';

    /**
     * Arrange a registered, login-ready user. The raw password is hashed by the
     * model's `hashed` cast on save, so the user authenticates with $password.
     * Shared by the login (UC-1-002) and logout (UC-1-004) suites.
     */
    protected function aRegisteredUser(
        string $email = self::LOGIN_EMAIL,
        string $password = self::LOGIN_PASSWORD,
    ): User {
        return User::factory()->withEmail($email)->withPassword($password)->create();
    }

    /**
     * The canonical happy owner-signup body, shared by the register happy-path
     * and sad-path suites (§2.10). Individual tests override single fields.
     *
     * @return array<string, mixed>
     */
    protected function validRegistrationPayload(): array
    {
        return [
            'name' => 'Nimal Perera',
            'email' => 'nimal@contoso.lk',
            'password' => 'S3cure-Pass!',
            'acceptedTos' => true,
            'companyName' => 'Contoso',
            'brNumber' => 'PV-12345678',
            'epfNumber' => 'A-1234567',
            'country' => 'LK',
            'emergencyContactName' => 'Kamala Perera',
            'emergencyContactEmail' => 'kamala@contoso.lk',
            'emergencyContactPhone' => '+94771234567',
            'emergencyContactRelationship' => 'spouse',
        ];
    }
}
