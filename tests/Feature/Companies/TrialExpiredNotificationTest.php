<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Aggregates\Company;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Notification;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Entities\Company as CompanyReadModel;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Foundation\Notifications\Notifications\SystemNotification;

class TrialExpiredNotificationTest extends CompaniesTestCase
{
    use RefreshDatabase;

    // Sad — a company with no owner (arranged purely by event replay, as the trial-lifecycle
    // slice does before onboarding links one) expires without error and sends nothing. Guards
    // the auto-discovered reactor against the owner-less companies #07's scan already expires.
    #[Test]
    public function when_an_expired_company_has_no_owner_then_no_notification_is_sent(): void
    {
        // Given a trial company with no owner linked
        Notification::fake();
        $companyId = (string) Str::uuid();
        $this->createCompanyOnTrial($companyId);

        // When the trial expires
        $this->expire($companyId);

        // Then nothing is sent
        Notification::assertNothingSent();
    }

    // Happy (UC-1-049 Sc1) — when a trial expires the company's owner is emailed "your trial
    // has ended"; other members are not (the read-only banner for everyone else is frontend).
    #[Test]
    public function when_a_trial_expires_then_only_the_owner_is_notified(): void
    {
        // Given a trial company with an owner and a non-owner member
        Notification::fake();
        $companyId = (string) Str::uuid();
        $this->createCompanyOnTrial($companyId);
        $owner = $this->attachUserWithRole($companyId, 'owner');
        $member = $this->attachUserWithRole($companyId, 'admin');

        // When the trial expires
        $this->expire($companyId);

        // Then only the owner receives the trial-ended notification
        $this->assertOnlyOwnerNotified($owner, $member, 'Contoso');
    }

    protected function createCompanyOnTrial(string $companyId, string $name = 'Contoso'): void
    {
        Company::retrieve($companyId)->create($name)->persist();
    }

    protected function expire(string $companyId): void
    {
        Company::retrieve($companyId)->trialExpire()->persist();
    }

    protected function attachUserWithRole(string $companyId, string $roleCode): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->code($roleCode)->create();
        CompanyReadModel::query()->findOrFail($companyId)->users()->attach($user, ['role_id' => $role->id]);

        return $user;
    }

    protected function assertOnlyOwnerNotified(User $owner, User $member, string $companyName): void
    {
        Notification::assertSentTo(
            $owner,
            SystemNotification::class,
            fn (SystemNotification $notification): bool => $notification->type === NotificationType::GeneralAccountNotice
                && $notification->companyName === $companyName,
        );
        Notification::assertNotSentTo($member, SystemNotification::class);
    }
}
