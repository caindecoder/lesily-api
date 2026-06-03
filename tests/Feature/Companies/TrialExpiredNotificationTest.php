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

    private const COMPANY_NAME = 'Contoso';

    // Sad — a company with members but no one holding the Owner role expires without error and
    // sends nothing. This is production's real no-owner case (the owner role is always seeded;
    // it is the link that is absent), and it pins the owner-role filter: a "notify any member"
    // regression would email the admin here.
    #[Test]
    public function when_an_expired_company_has_members_but_no_owner_then_no_notification_is_sent(): void
    {
        // Given a trial company with an admin member but no owner, with the owner role seeded
        Notification::fake();
        $companyId = (string) Str::uuid();
        $this->createCompanyOnTrial($companyId);
        $this->attachUserWithRole($companyId, 'admin');
        $this->ensureRoleExists('owner');

        // When the trial expires
        $this->expire($companyId);

        // Then nothing is sent
        Notification::assertNothingSent();
    }

    // Happy (UC-1-049 Sc1) — when a trial expires the owner is emailed "your trial has ended"
    // with the company name; other members are not (their read-only banner is frontend). The
    // email channel/locale/opt-out behaviour is covered by UC-1-087's ReceiveEmailNotificationTest.
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
        $this->assertNotifiedExactly($owner, $member, self::COMPANY_NAME);
    }

    // Edge (tenant isolation) — expiry is scoped to the expiring company; another company's
    // owner is never notified for it. Guards the company-scoped owner resolution against a
    // regression that drops the scope (cross-tenant wrong-recipient leak).
    #[Test]
    public function when_a_trial_expires_then_another_companys_owner_is_not_notified(): void
    {
        // Given two trial companies, each with its own owner
        Notification::fake();
        $companyId = (string) Str::uuid();
        $this->createCompanyOnTrial($companyId);
        $owner = $this->attachUserWithRole($companyId, 'owner');
        $otherOwner = $this->attachUserWithRole($this->givenOtherTrialCompany(), 'owner');

        // When the first company's trial expires
        $this->expire($companyId);

        // Then only its own owner is notified, not the other company's
        $this->assertNotifiedExactly($owner, $otherOwner, self::COMPANY_NAME);
    }

    protected function createCompanyOnTrial(string $companyId, string $name = self::COMPANY_NAME): void
    {
        Company::retrieve($companyId)->create($name)->persist();
    }

    protected function givenOtherTrialCompany(): string
    {
        $otherCompanyId = (string) Str::uuid();
        $this->createCompanyOnTrial($otherCompanyId, 'ACME');

        return $otherCompanyId;
    }

    protected function expire(string $companyId): void
    {
        Company::retrieve($companyId)->trialExpire()->persist();
    }

    protected function attachUserWithRole(string $companyId, string $roleCode): User
    {
        $user = User::factory()->create();
        CompanyReadModel::query()->findOrFail($companyId)
            ->users()->attach($user, ['role_id' => $this->ensureRoleExists($roleCode)->id]);

        return $user;
    }

    protected function ensureRoleExists(string $roleCode): Role
    {
        return Role::findByCode($roleCode) ?? Role::factory()->code($roleCode)->create();
    }

    protected function assertNotifiedExactly(User $recipient, User $notNotified, string $companyName): void
    {
        Notification::assertSentTo(
            $recipient,
            SystemNotification::class,
            fn (SystemNotification $notification): bool => $notification->type === NotificationType::GeneralAccountNotice
                && $notification->companyName === $companyName,
        );
        Notification::assertNotSentTo($notNotified, SystemNotification::class);
    }
}
