<?php

namespace Tests\Feature\Auth\Password;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use Illuminate\Support\Facades\Notification;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\Auth\Events\UserPasswordChanged;
use App\Foundation\Notifications\Enums\NotificationType;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
use App\Foundation\Notifications\Entities\NotificationPreference;
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
    use InteractsWithQueuedReactors;

    protected function setUp(): void
    {
        parent::setUp();

        // Error-path render parity (amendment M): APP_DEBUG=true (the local default) masks the
        // prod render path, so the Sad 401 rejection renders its mapped envelope the way CI/prod do.
        config(['app.debug' => false]);
    }

    #[Test]
    public function when_a_change_attempt_fails_then_no_confirmation_is_sent(): void
    {
        // Given a user with an active session
        $user = $this->aRegisteredUser();
        $session = $this->aSessionFor($user);
        Notification::fake();

        // When they submit the wrong current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => 'wrong-password',
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the change is refused and no confirmation is sent — the notice is bound to the event
        $this->assertRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::IncorrectPassword);
        Notification::assertNothingSent();
    }

    #[Test]
    public function when_the_owner_has_opted_out_then_the_confirmation_is_still_sent(): void
    {
        // Given a user who has opted their email out of the password-changed notice
        $user = $this->aRegisteredUser();
        NotificationPreference::factory()->mailDisabled($user, NotificationType::PasswordChanged)->create();
        $session = $this->aSessionFor($user);
        Mail::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the confirmation email is still queued — a critical notice cannot be silenced
        $response->assertNoContent();
        Mail::assertQueued(
            SystemNotificationMail::class,
            fn (SystemNotificationMail $mail): bool => $mail->type === NotificationType::PasswordChanged
                && $mail->hasTo($user->email),
        );
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
        $this->assertConfirmationNames($response, $user, $company->name);
    }

    #[Test]
    public function when_a_reset_attempt_fails_then_no_confirmation_is_sent(): void
    {
        // Given a registered user and an invalid (never-issued) reset token
        $user = $this->aRegisteredUser();
        Notification::fake();

        // When they submit a reset with that token
        $response = $this->resetPassword([
            'token' => 'never-issued-token',
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ]);

        // Then the reset is refused and no confirmation is sent — the notice is bound to the stored event
        $this->assertRefusedWith($response, Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::ResetLinkInvalid);
        Notification::assertNothingSent();
    }

    #[Test]
    public function when_the_password_is_reset_then_a_confirmation_is_sent(): void
    {
        // Given a company-attached user with a valid reset token
        $user = $this->aRegisteredUser();
        $company = $this->attachCompany($user);
        $token = $this->aResetTokenFor($user);
        Notification::fake();

        // When they reset their password with a valid new one
        $response = $this->resetPassword([
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ]);

        // Then the same password-changed confirmation is sent — the reset event is wired into the
        // same reactor as the change event
        $this->assertConfirmationNames($response, $user, $company->name);
    }

    #[Test]
    public function when_a_company_less_user_changes_their_password_then_the_label_is_empty(): void
    {
        // Given a user who belongs to no company
        $user = $this->aRegisteredUser();
        $session = $this->aSessionFor($user);
        Notification::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the confirmation still goes out with an empty company label — degrade, never fail
        $this->assertConfirmationNames($response, $user, '');
    }

    #[Test]
    public function when_the_user_has_no_default_company_then_the_oldest_membership_names_the_confirmation(): void
    {
        // Given a defaultless two-company user whose OLDEST membership was inserted second —
        // insertion order deliberately inverted vs joined time, so an unordered read picks wrong
        $user = $this->aRegisteredUser();
        $this->attachCompany($user, now());
        $oldest = $this->attachCompany($user, now()->subDay());
        $session = $this->aSessionFor($user);
        Notification::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the confirmation is labeled with the oldest membership's company
        $this->assertConfirmationNames($response, $user, $oldest->name);
    }

    #[Test]
    public function when_memberships_join_at_the_same_moment_then_the_earlier_row_names_the_confirmation(): void
    {
        // Given a defaultless user with two memberships joined at the same moment — the pivot
        // id (uuid7, insertion-ordered) is the only thing separating them
        $user = $this->aRegisteredUser();
        $joinedAt = now();
        $earlier = $this->attachCompany($user, $joinedAt);
        $this->attachCompany($user, $joinedAt);
        $session = $this->aSessionFor($user);
        Notification::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the earlier-inserted row wins the tie — the id tiebreak keeps the label deterministic
        $this->assertConfirmationNames($response, $user, $earlier->name);
    }

    #[Test]
    public function when_the_default_membership_was_removed_then_the_oldest_live_membership_names_the_confirmation(): void
    {
        // Given a user whose default company's membership was removed, leaving an older live one
        $user = $this->aRegisteredUser();
        $oldestLive = $this->attachCompany($user, now()->subDays(2));
        $removedDefault = $this->attachCompany($user, now());
        $user->forceFill(['default_company_id' => $removedDefault->getKey()])->save();
        CompanyUser::query()
            ->where('company_id', $removedDefault->getKey())
            ->where('user_id', $user->getKey())
            ->update(['deleted_at' => now()]);
        $session = $this->aSessionFor($user);
        Notification::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the label falls back to the oldest LIVE membership — a dead default never leaks
        $this->assertConfirmationNames($response, $user, $oldestLive->name);
    }

    #[Test]
    public function when_the_user_has_a_default_company_then_it_names_the_confirmation(): void
    {
        // Given a two-company user whose default is the NEWER membership — the one neither
        // an arbitrary-first nor an oldest-first read would pick
        $user = $this->aRegisteredUser();
        $this->attachCompany($user, now()->subDays(2));
        $default = $this->attachCompany($user, now());
        $user->forceFill(['default_company_id' => $default->getKey()])->save();
        $session = $this->aSessionFor($user);
        Notification::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the confirmation is labeled with the default company — the user's own choice wins
        $this->assertConfirmationNames($response, $user, $default->name);
    }

    #[Test]
    public function when_the_password_is_changed_then_the_confirmation_is_queued_not_sent_inline(): void
    {
        // Given a company-attached user with an active session, and the fakes armed
        $user = $this->aRegisteredUser();
        $company = $this->attachCompany($user);
        $session = $this->aSessionFor($user);
        Notification::fake();
        Queue::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the confirmation is deferred — not sent inline — and delivers to them at the drain
        $this->assertReactorDeferred(UserPasswordChanged::class, SystemNotification::class);
        $this->runQueuedHandlersFor(UserPasswordChanged::class);
        $this->assertConfirmationNames($response, $user, $company->name);
    }

    /**
     * The password-changed confirmation reached $user carrying exactly $companyName as its
     * label — the change was accepted and the critical notice names the expected company
     * (the OQ-1-124 deterministic-label contract: default → oldest live membership → '').
     */
    protected function assertConfirmationNames(TestResponse $response, User $user, string $companyName): void
    {
        $response->assertNoContent();

        Notification::assertSentTo(
            $user,
            SystemNotification::class,
            fn (SystemNotification $notification): bool => $notification->type === NotificationType::PasswordChanged
                && $notification->companyName === $companyName
                && $notification->companyId === null,
        );
    }

    /**
     * Make $user a member of a freshly-created company and return that company so the caller can
     * assert the confirmation carries its name — the notification's companyName is resolved from
     * the acting user's membership. The name is unique-per-test so the tier-distinguishing
     * assertions cannot be satisfied by a faker name collision between two companies (a wrong
     * resolution must FAIL, never alias). $joinedAt backdates the pivot's created_at AFTER
     * attaching (attach-then-update, the aRemovedMembershipIn pattern) because withTimestamps()
     * overwrites an explicitly-passed created_at — it lets a test invert insertion order vs
     * joined time.
     */
    protected function attachCompany(User $user, ?CarbonInterface $joinedAt = null): Company
    {
        $company = Company::factory()->named(fake()->unique()->company())->create();
        $company->users()->attach($user, ['role_id' => Role::factory()->create()->getKey()]);

        if ($joinedAt !== null) {
            CompanyUser::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $user->getKey())
                ->update(['created_at' => $joinedAt]);
        }

        return $company;
    }
}
