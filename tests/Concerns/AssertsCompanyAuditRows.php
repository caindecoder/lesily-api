<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Events\CompanyCreated;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
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
}
