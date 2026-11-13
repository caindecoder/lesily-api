<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\CompanyUser;
use App\Consent\Entities\ConsentRecord;
use App\Companies\Events\CompanyCreated;
use App\Companies\Events\CompanySettingReset;
use App\Companies\Events\CompanyDetailsUpdated;
use App\Companies\Events\CompanySettingsUpdated;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

trait AssertsCompanyAuditRows
{
    protected function assertCompanyCreationAudited(TestResponse $response, Company $company, User $owner): void
    {
        $requestId = $response->headers->get('Correlation-Id');
        $this->assertIsString($requestId);
        $createdEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $company->getKey())
            ->where('event_class', CompanyCreated::class)
            ->sole();
        $entry = AuditEntry::query()->where('action', AuditAction::CompanyCreated)->sole();

        $this->assertSame([
            'company_id' => $company->getKey(),
            'stored_event_id' => $createdEvent->getKey(),
            'event_class' => 'CompanyCreated',
            'subject' => ['company', $company->getKey(), $company->name],
            'description' => 'Company created',
            'actor' => [$owner->getKey(), 'owner', $owner->name],
            'origin' => [self::DEVICE_IP, self::DEVICE, $requestId],
            'metadata' => ['name' => $company->name, 'country' => $company->country],
            'severity' => 'info',
        ], [
            'company_id' => $entry->company_id,
            'stored_event_id' => $entry->stored_event_id,
            'event_class' => $entry->event_class,
            'subject' => [$entry->subject_type->value, $entry->subject_id, $entry->subject_label],
            'description' => $entry->description,
            'actor' => [$entry->actor_user_id, $entry->actor_role_code, $entry->actor_label],
            'origin' => [$entry->ip_address, $entry->user_agent, $entry->request_id],
            'metadata' => $entry->metadata,
            'severity' => $entry->severity,
        ]);
    }

    protected function assertConsentsAudited(TestResponse $response, Company $company, User $user): void
    {
        $requestId = $response->headers->get('Correlation-Id');
        $this->assertIsString($requestId);
        $entries = AuditEntry::query()
            ->where('company_id', $company->getKey())
            ->where('action', AuditAction::ConsentRecorded)
            ->get()
            ->keyBy(fn (AuditEntry $entry): string => $entry->metadata['policy'] ?? '');
        /** @var array<string, string> $liveVersions */
        $liveVersions = config('lesily.policies');
        $this->assertCount(2, $entries);

        foreach ($liveVersions as $policy => $version) {
            $record = ConsentRecord::query()
                ->where(['user_id' => $user->getKey(), 'company_id' => $company->getKey(), 'policy_type' => $policy, 'version' => $version])
                ->sole();
            $entry = $entries->get($policy);
            $this->assertInstanceOf(AuditEntry::class, $entry);

            $this->assertSame([
                'stored_event_id' => $record->stored_event_id,
                'event_class' => 'ConsentRecorded',
                'subject' => ['consent', $record->getKey(), null],
                'description' => 'Consent recorded',
                'actor' => [$user->getKey(), $this->roleCodeIn($company, $user), $user->name],
                'origin' => [self::DEVICE_IP, self::DEVICE, $requestId],
                'metadata' => ['policy' => $policy, 'version' => $version],
                'severity' => 'info',
            ], [
                'stored_event_id' => $entry->stored_event_id,
                'event_class' => $entry->event_class,
                'subject' => [$entry->subject_type->value, $entry->subject_id, $entry->subject_label],
                'description' => $entry->description,
                'actor' => [$entry->actor_user_id, $entry->actor_role_code, $entry->actor_label],
                'origin' => [$entry->ip_address, $entry->user_agent, $entry->request_id],
                'metadata' => $entry->metadata,
                'severity' => $entry->severity,
            ]);
        }
    }

    /** @param array<string, array{from: mixed, to: mixed}> $changes */
    protected function assertCompanyChangeAudited(Company $company, AuditAction $action, array $changes): void
    {
        ksort($changes);
        $entry = AuditEntry::query()
            ->where('company_id', $company->getKey())
            ->where('action', $action)
            ->latest('recorded_at')
            ->latest('id')
            ->get()
            ->first(fn (AuditEntry $entry): bool => array_keys($this->changesOf($entry)) === array_keys($changes));
        $this->assertInstanceOf(AuditEntry::class, $entry);
        [$eventClass, $words] = match ($action) {
            AuditAction::CompanyDetailsUpdated => [CompanyDetailsUpdated::class, 'Company details updated'],
            AuditAction::CompanySettingsUpdated => [CompanySettingsUpdated::class, 'Company settings updated'],
            default => [CompanySettingReset::class, 'Company setting reset to the country default'],
        };
        $storedEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $company->getKey())
            ->where('event_class', $eventClass)
            ->when($eventClass === CompanySettingReset::class, fn ($query) => $query->where('event_properties->setting', array_key_first($changes)))
            ->latest('id')
            ->firstOrFail();

        $this->assertSame([
            'stored_event_id' => $storedEvent->getKey(),
            'event_class' => class_basename($eventClass),
            'subject' => ['company', $company->getKey(), $company->refresh()->name],
            'description' => $words,
            'changes' => $changes,
            'severity' => 'info',
        ], [
            'stored_event_id' => $entry->stored_event_id,
            'event_class' => $entry->event_class,
            'subject' => [$entry->subject_type->value, $entry->subject_id, $entry->subject_label],
            'description' => $entry->description,
            'changes' => $this->changesOf($entry),
            'severity' => $entry->severity,
        ]);
    }

    protected function roleCodeIn(Company $company, User $member): ?string
    {
        return Role::query()
            ->whereKey(CompanyUser::query()->where(['company_id' => $company->getKey(), 'user_id' => $member->getKey()])->value('role_id'))
            ->value('code');
    }
}
