<?php

namespace Tests\Feature\Auth\Password;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
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
    use FreeTierCompany, InteractsWithQueuedReactors;

    protected function setUp(): void
    {
        parent::setUp();

        // Error-path render parity (amendment M): APP_DEBUG=true (the local default) masks the
        // prod render path, so the Sad 401 rejection renders its mapped envelope the way CI/prod do.
        config(['app.debug' => false]);
        $this->makeFreeTierCompany();
        $this->employee->update(['password' => self::LOGIN_PASSWORD]);
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
        // Given the employee of Contoso with an active session
        $session = $this->aSessionFor($this->employee);
        Notification::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then a password-changed confirmation naming their company is sent to them
        $this->assertConfirmationNames($response, $this->employee, $this->company->name);
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
        // Given the employee of Contoso with a valid reset token
        $token = $this->aResetTokenFor($this->employee);
        Notification::fake();

        // When they reset their password with a valid new one
        $response = $this->resetPassword([
            'token' => $token,
            'email' => $this->employee->email,
            'password' => self::NEW_PASSWORD,
        ]);

        // Then the same password-changed confirmation is sent — the reset event is wired into the
        // same reactor as the change event
        $this->assertConfirmationNames($response, $this->employee, $this->company->name);
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
        // Given the employee — defaultless, joined Contoso first — whose OLDEST membership was
        // inserted second: insertion order deliberately inverted vs joined time, so an unordered read picks wrong
        $oldest = $this->attachCompany($this->employee, now()->subDay());
        $session = $this->aSessionFor($this->employee);
        Notification::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the confirmation is labeled with the oldest membership's company
        $this->assertConfirmationNames($response, $this->employee, $oldest->name);
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
        // Given the employee whose default company — Contoso — had its membership removed,
        // leaving an older live one
        $oldestLive = $this->attachCompany($this->employee, now()->subDays(2));
        $this->employee->forceFill(['default_company_id' => $this->company->getKey()])->save();
        $this->softDeleteMembership($this->company, $this->employee);
        $session = $this->aSessionFor($this->employee);
        Notification::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the label falls back to the oldest LIVE membership — a dead default never leaks
        $this->assertConfirmationNames($response, $this->employee, $oldestLive->name);
    }

    #[Test]
    public function when_the_user_has_a_default_company_then_it_names_the_confirmation(): void
    {
        // Given the employee — first a member of Contoso — whose default is the NEWER, last-inserted
        // membership: the one neither an arbitrary-first nor an oldest-first read would pick
        $this->attachCompany($this->employee, now()->subDays(2));
        $default = $this->attachCompany($this->employee, now());
        $this->employee->forceFill(['default_company_id' => $default->getKey()])->save();
        $session = $this->aSessionFor($this->employee);
        Notification::fake();

        // When they change their password with the correct current password
        $response = $this->changePassword($session->plainTextToken, [
            'currentPassword' => self::LOGIN_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        // Then the confirmation is labeled with the default company — the user's own choice wins
        $this->assertConfirmationNames($response, $this->employee, $default->name);
    }

    #[Test]
    public function when_the_password_is_changed_then_the_confirmation_is_queued_not_sent_inline(): void
    {
        // Given the employee of Contoso with an active session, and the fakes armed
        $session = $this->aSessionFor($this->employee);
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
        $this->assertConfirmationNames($response, $this->employee, $this->company->name);
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
     * attaching (attach-then-update, the shape of CreatesMemberships::aRemovedMembership) because withTimestamps()
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
