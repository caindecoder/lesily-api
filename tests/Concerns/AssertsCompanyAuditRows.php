<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\CompanyUser;
use App\Consent\Entities\ConsentRecord;
use App\Companies\Events\CompanyCreated;
use Illuminate\Database\Eloquent\Builder;
use App\Companies\Events\CompanySettingReset;
use App\Companies\Events\CompanyDetailsUpdated;
use App\Companies\Events\CompanySettingsUpdated;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AuditTrail\Values\FieldChanges;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Companies\Events\CompanyDataProtectionOfficerSet;
use App\Companies\Events\CompanyDataProtectionOfficerRemoved;
use App\Foundation\Auth\Events\UserMembershipEmergencyContactSet;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

trait AssertsCompanyAuditRows
{
    protected function assertCompanyCreationAudited(TestResponse $response, Company $company, User $owner): void
    {
        $requestId = $response->headers->get('Correlation-Id');
        $this->assertIsString($requestId);
        $eloquentStoredEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $company->getKey())
            ->where('event_class', CompanyCreated::class)
            ->sole();
        $auditEntry = AuditEntry::query()->where('action', AuditAction::CompanyCreated)->sole();

        $this->assertSame([
            'company_id' => $company->getKey(),
            'stored_event_id' => $eloquentStoredEvent->getKey(),
            'event_class' => 'CompanyCreated',
            'subject' => ['company', $company->getKey(), $company->name],
            'description' => 'Company created',
            'actor' => [$owner->getKey(), 'owner', $owner->name],
            'origin' => [self::DEVICE_IP, self::DEVICE, $requestId],
            'metadata' => ['name' => $company->name, 'country' => $company->country],
            'severity' => 'info',
        ], [
            'company_id' => $auditEntry->company_id,
            'stored_event_id' => $auditEntry->stored_event_id,
            'event_class' => $auditEntry->event_class,
            'subject' => [$auditEntry->subject_type->value, $auditEntry->subject_id, $auditEntry->subject_label],
            'description' => $auditEntry->description,
            'actor' => [$auditEntry->actor_user_id, $auditEntry->actor_role_code, $auditEntry->actor_label],
            'origin' => [$auditEntry->ip_address, $auditEntry->user_agent, $auditEntry->request_id],
            'metadata' => $auditEntry->metadata,
            'severity' => $auditEntry->severity,
        ]);
    }

    protected function assertConsentsAudited(TestResponse $response, Company $company, User $user): void
    {
        $requestId = $response->headers->get('Correlation-Id');
        $this->assertIsString($requestId);
        $auditEntries = AuditEntry::query()
            ->where('company_id', $company->getKey())
            ->where('action', AuditAction::ConsentRecorded)
            ->where('request_id', $requestId)
            ->get();
        $this->assertCount(2, $auditEntries);
        $policyAuditEntries = $auditEntries->keyBy(fn (AuditEntry $auditEntry): string => $auditEntry->metadata['policy'] ?? '');
        /** @var array<string, string> $liveVersions */
        $liveVersions = config('lesily.policies');

        foreach ($liveVersions as $policy => $version) {
            $consent = ConsentRecord::query()
                ->where(['user_id' => $user->getKey(), 'company_id' => $company->getKey(), 'policy_type' => $policy, 'version' => $version])
                ->sole();
            $auditEntry = $policyAuditEntries->get($policy);
            $this->assertInstanceOf(AuditEntry::class, $auditEntry);

            $this->assertSame([
                'stored_event_id' => $consent->stored_event_id,
                'event_class' => 'ConsentRecorded',
                'subject' => ['consent', $consent->getKey(), null],
                'description' => 'Consent recorded',
                'actor' => [$user->getKey(), $this->roleCodeIn($company, $user), $user->name],
                'origin' => [self::DEVICE_IP, self::DEVICE, $requestId],
                'metadata' => ['policy' => $policy, 'version' => $version],
                'severity' => 'info',
            ], [
                'stored_event_id' => $auditEntry->stored_event_id,
                'event_class' => $auditEntry->event_class,
                'subject' => [$auditEntry->subject_type->value, $auditEntry->subject_id, $auditEntry->subject_label],
                'description' => $auditEntry->description,
                'actor' => [$auditEntry->actor_user_id, $auditEntry->actor_role_code, $auditEntry->actor_label],
                'origin' => [$auditEntry->ip_address, $auditEntry->user_agent, $auditEntry->request_id],
                'metadata' => $auditEntry->metadata,
                'severity' => $auditEntry->severity,
            ]);
        }
    }

    /** @param array<string, array{from: mixed, to: mixed}> $changes */
    protected function assertCompanyChangeAudited(Company $company, AuditAction $auditAction, array $changes): void
    {
        ksort($changes);
        $auditEntry = AuditEntry::query()
            ->where('company_id', $company->getKey())
            ->where('action', $auditAction)
            ->latest('recorded_at')
            ->latest('id')
            ->get()
            ->first(fn (AuditEntry $auditEntry): bool => array_keys($this->readableChangesOf($auditEntry)) === array_keys($changes));
        $this->assertInstanceOf(AuditEntry::class, $auditEntry);
        [$eventClass, $words] = match ($auditAction) {
            AuditAction::DataProtectionOfficerSet => [CompanyDataProtectionOfficerSet::class, 'Data protection officer set'],
            AuditAction::DataProtectionOfficerRemoved => [CompanyDataProtectionOfficerRemoved::class, 'Data protection officer removed'],
            AuditAction::CompanyDetailsUpdated => [CompanyDetailsUpdated::class, 'Company details updated'],
            AuditAction::CompanySettingsUpdated => [CompanySettingsUpdated::class, 'Company settings updated'],
            default => [CompanySettingReset::class, 'Company setting reset to the country default'],
        };
        $eloquentStoredEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $company->getKey())
            ->where('event_class', $eventClass)
            ->when($eventClass === CompanySettingReset::class, fn (Builder $builder) => $builder->where('event_properties->setting', array_key_first($changes)))
            ->latest('id')
            ->firstOrFail();

        $this->assertSame([
            'stored_event_id' => $eloquentStoredEvent->getKey(),
            'event_class' => class_basename($eventClass),
            'subject' => ['company', $company->getKey(), $company->refresh()->name],
            'description' => $words,
            'changes' => $changes,
            'severity' => 'info',
        ], [
            'stored_event_id' => $auditEntry->stored_event_id,
            'event_class' => $auditEntry->event_class,
            'subject' => [$auditEntry->subject_type->value, $auditEntry->subject_id, $auditEntry->subject_label],
            'description' => $auditEntry->description,
            'changes' => $this->readableChangesOf($auditEntry),
            'severity' => $auditEntry->severity,
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function readableChangesOf(AuditEntry $auditEntry): array
    {
        $changes = FieldChanges::readable($this->changesOf($auditEntry)) ?? [];
        array_walk($changes, fn (array &$change): bool => ksort($change));

        return $changes;
    }

    /** @param array<string, array{from: ?string, to: ?string}> $plain */
    protected function assertContactChangeAudited(TestResponse $response, CompanyUser $companyUser, array $plain): void
    {
        $requestId = $response->headers->get('Correlation-Id');
        $this->assertIsString($requestId);
        $owner = User::query()->findOrFail($companyUser->user_id);
        $eloquentStoredEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $owner->getKey())
            ->where('event_class', UserMembershipEmergencyContactSet::class)
            ->whereForCompany($companyUser->company_id)
            ->latest('id')
            ->firstOrFail();
        $auditEntries = AuditEntry::query()
            ->where('action', AuditAction::EmergencyContactUpdated)
            ->where('company_id', $companyUser->company_id)
            ->where('request_id', $requestId)
            ->get();
        $this->assertCount(1, $auditEntries);
        $auditEntry = $auditEntries->sole();

        $this->assertSame([
            'stored_event_id' => $eloquentStoredEvent->getKey(),
            'subject' => ['user', $owner->getKey(), $owner->name],
            'membership_id' => $companyUser->getKey(),
            'actor' => [$owner->getKey(), 'owner', $owner->name],
            'origin' => [self::DEVICE_IP, self::DEVICE, $requestId],
            'severity' => 'info',
        ], [
            'stored_event_id' => $auditEntry->stored_event_id,
            'subject' => [$auditEntry->subject_type->value, $auditEntry->subject_id, $auditEntry->subject_label],
            'membership_id' => $auditEntry->metadata['membership_id'] ?? null,
            'actor' => [$auditEntry->actor_user_id, $auditEntry->actor_role_code, $auditEntry->actor_label],
            'origin' => [$auditEntry->ip_address, $auditEntry->user_agent, $auditEntry->request_id],
            'severity' => $auditEntry->severity,
        ]);
        $this->assertEncryptedChanges($auditEntry, $plain);
    }

    protected function membershipOf(Company $company, User $member): CompanyUser
    {
        return CompanyUser::query()->where(['company_id' => $company->getKey(), 'user_id' => $member->getKey()])->sole();
    }

    protected function roleCodeIn(Company $company, User $member): ?string
    {
        return Role::query()
            ->whereKey(CompanyUser::query()->where(['company_id' => $company->getKey(), 'user_id' => $member->getKey()])->value('role_id'))
            ->value('code');
    }
}
