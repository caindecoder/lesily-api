<?php

namespace Tests\Feature\Auth\Password;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Notifications\SystemNotification;

/**
 * The "your password was changed" confirmation email (UC-1-016 increment #2). A successful
 * password change fires UserPasswordChanged, which PasswordChangedNotificationReactor turns
 * into a critical SystemNotification — so the confirmation is bound to the STORED EVENT, not
 * the request (a failed change sends nothing), and its critical tier bypasses opt-out (an
 * account-security notice cannot be silenced, questionnaire S16 L474).
 */
class PasswordChangedNotificationTest extends AuthTestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'New-Str0ng-Pass!';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    #[Test]
    public function when_the_password_is_changed_then_a_confirmation_is_sent(): void
    {
        // Given a company-attached user with an active session
        $user = $this->aRegisteredUser();
        $company = $this->attachCompany($user);
        $session = $this->aSessionFor($user);
        Notification::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then a password-changed confirmation naming their company is sent to them
        $response->assertNoContent();
        Notification::assertSentTo(
            $user,
            SystemNotification::class,
            fn (SystemNotification $notification): bool => $notification->type === NotificationType::PasswordChanged
                && $notification->companyName === $company->name,
        );
    }

    /**
     * Submit a password change to the self-service endpoint (which requires an idempotency key).
     *
     * @param  array<string, string>  $payload
     */
    private function changePassword(string $token, array $payload, ?string $idempotencyKey = null): TestResponse
    {
        return $this->withToken($token)->putJson('/api/v1/me/password', $payload, [
            'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);
    }

    /**
     * Make $user a member of a freshly-created company and return that company so the caller can
     * assert the confirmation carries its name — the notification's companyName is resolved from
     * the acting user's membership.
     */
    private function attachCompany(User $user): Company
    {
        $company = Company::factory()->create();
        $company->users()->attach($user, ['role_id' => Role::factory()->create()->getKey()]);

        return $company;
    }
}
