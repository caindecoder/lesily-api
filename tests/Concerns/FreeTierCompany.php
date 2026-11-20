<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Companies\Entities\Company;
use Tests\Feature\Billing\HasSubscription;
use App\Foundation\Billing\Enums\BillingPlan;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Billing\Subscriptions\Aggregates\Subscription;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Companies\UseCases\Interactors\CreateCompanyInteractor;
use App\Billing\Subscriptions\Entities\Subscription as SubscriptionReadModel;

trait FreeTierCompany
{
    use HasSubscription;

    protected const int READY_MADE_AUDIT_ROWS = 4;

    protected const int READY_MADE_NON_CONSENT_EVENTS = 5;

    protected const int READY_MADE_CONTACT_EVENTS = 1;

    protected Company $company;

    protected User $owner;

    protected User $admin;

    protected User $employee;

    protected function makeFreeTierCompany(): void
    {
        $this->seedRbacCatalog();
        $this->owner = User::factory()->create();
        $this->company = $this->createFreeCompanyFor($this->owner);
        $this->admin = $this->aCompanyMember($this->company, 'admin');
        $this->employee = $this->aCompanyMember($this->company);
    }

    protected function createFreeCompanyFor(User $owner): Company
    {
        $company = app(CreateCompanyInteractor::class)->execute(CreateCompanyCommand::from([
            'name' => 'Contoso',
            'brNumber' => fake()->unique()->numerify('PV-########'),
            'epfNumber' => fake()->unique()->numerify('EPF-#######'),
            'country' => 'LK',
            'owner' => $owner,
            'emergencyContactName' => 'Kamala Perera',
            'emergencyContactEmail' => 'kamala@contoso.lk',
            'emergencyContactPhone' => '+94771234567',
            'emergencyContactRelationship' => 'spouse',
        ]), new RequestOrigin('198.51.100.4', null, null));

        Subscription::retrieve(SubscriptionReadModel::query()->where('company_id', $company->getKey())->sole()->getKey())
            ->endTrial('trial-expired')
            ->persist();
        $company->update(['current_billing_plan' => BillingPlan::Free, 'trial_ends_at' => null]);

        return $company;
    }

    protected function memberWithRole(string $roleCode): User
    {
        return match ($roleCode) {
            'owner' => $this->owner,
            'admin' => $this->admin,
            'employee' => $this->employee,
            default => $this->aCompanyMember($this->company, $roleCode),
        };
    }

    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    protected function aCompanyMember(Company $company, string $roleCode = 'employee'): User
    {
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);
        $this->aConsentRecordedBy($user, $company);

        return $user;
    }
}
