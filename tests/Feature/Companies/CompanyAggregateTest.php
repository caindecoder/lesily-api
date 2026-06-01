<?php

namespace Tests\Feature\Companies;

use Illuminate\Support\Str;
use App\Companies\Aggregates\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Events\CompanyCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Exceptions\CompanyAlreadyExistsException;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class CompanyAggregateTest extends CompaniesTestCase
{
    use RefreshDatabase;

    // Sad — genesis is illegal from any state but [*]: an existing company cannot be re-created.
    #[Test]
    public function when_a_company_that_already_exists_is_created_again_then_it_is_rejected(): void
    {
        // Given a company that was already created
        $companyId = (string) Str::uuid();
        Company::retrieve($companyId)->create('Contoso')->persist();

        // When the same company is created again, Then it is rejected as already existing
        $this->expectException(CompanyAlreadyExistsException::class);

        Company::retrieve($companyId)->create('Contoso Again');
    }

    // Happy — genesis: creating a company opens a 30-day trial, scoped to its own tenant.
    #[Test]
    public function when_a_company_is_created_then_a_company_created_event_is_stored_in_its_own_company_scope_opening_a_30_day_trial(): void
    {
        // Given a new company id
        $companyId = (string) Str::uuid();

        // When the company is created and persisted
        Company::retrieve($companyId)->create('Contoso')->persist();

        // Then a CompanyCreated event is stored, scoped to the company, opening a 30-day trial
        $event = LesilyStoredEvent::query()->whereForCompany($companyId)->sole();

        $this->assertCompanyCreated($event, 'Contoso', 30);
    }

    protected function assertCompanyCreated(EloquentStoredEvent $event, string $expectedName, int $expectedTrialDays): void
    {
        $this->assertSame(CompanyCreated::class, $event->event_class);
        $this->assertSame($expectedName, $event->event_properties['name']);
        $this->assertSame($expectedTrialDays, $event->event_properties['trialDays']);
    }
}
