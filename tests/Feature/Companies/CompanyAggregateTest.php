<?php

namespace Tests\Feature\Companies;

use Illuminate\Support\Str;
use App\Companies\Aggregates\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Events\CompanyCreated;
use App\Companies\Events\CompanySettingsCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Companies\Exceptions\UnsupportedCountryException;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Companies\Exceptions\CompanyAlreadyExistsException;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class CompanyAggregateTest extends CompaniesTestCase
{
    use CreatesTrialCompanies;
    use RefreshDatabase;

    // Sad — genesis is illegal from any state but [*]: an existing company cannot be re-created.
    #[Test]
    public function when_a_company_that_already_exists_is_created_again_then_it_is_rejected(): void
    {
        // Given a company that was already created
        $companyId = (string) Str::uuid();
        $this->createCompanyOnTrial($companyId);

        // When the same company is created again, Then it is rejected as already existing
        $this->expectException(CompanyAlreadyExistsException::class);

        Company::retrieve($companyId)->create($this->buildCreateCompanyCommand($companyId, 'Contoso Again'));
    }

    // Sad — only launch-market countries have defaults to seed: an unsupported country is
    // rejected at the aggregate seam (the HTTP 422 country rule is the slice-05 guard above it).
    #[Test]
    public function when_a_company_is_created_for_an_unsupported_country_then_it_is_rejected(): void
    {
        // Given an owner-signup command for a country without seeded defaults
        $companyId = (string) Str::uuid();
        $command = $this->buildCreateCompanyCommandForCountry($companyId, 'US');

        // When the company is created, Then it is rejected
        $this->expectException(UnsupportedCountryException::class);

        Company::retrieve($companyId)->create($command);
    }

    protected function buildCreateCompanyCommandForCountry(string $companyId, string $country): CreateCompanyCommand
    {
        return CreateCompanyCommand::validateAndCreate(
            ['country' => $country] + $this->buildCreateCompanyCommand($companyId)->toArray()
        );
    }

    // Happy — genesis: creating a company opens a 30-day trial, scoped to its own tenant,
    // carrying the full registration payload (UC-1-031, slice C3/C4) and seeding the
    // default settings from the country defaults in the same record (CD-1-02 §Company).
    #[Test]
    public function when_a_company_is_created_then_a_company_created_event_is_stored_in_its_own_company_scope_opening_a_30_day_trial(): void
    {
        // Given a fixed point in time, a new company id, and an owner-signup command
        $this->travelTo('2026-06-01 00:00:00');
        $companyId = (string) Str::uuid();
        $command = $this->buildCreateCompanyCommand($companyId);

        // When the company is created and persisted
        Company::retrieve($companyId)->create($command)->persist();

        // Then a CompanyCreated event is stored, scoped to the company, mirroring the
        // command's registration payload and opening a trial that ends in 30 days —
        // alongside a CompanySettingsCreated carrying the LK country defaults
        $this->assertSame(2, LesilyStoredEvent::query()->whereForCompany($companyId)->count());

        $created = LesilyStoredEvent::query()->whereForCompany($companyId)
            ->where('event_class', CompanyCreated::class)->sole();
        $settings = LesilyStoredEvent::query()->whereForCompany($companyId)
            ->where('event_class', CompanySettingsCreated::class)->sole();

        $this->assertCompanyCreated($created, '2026-07-01T00:00:00+00:00');
        $this->assertEventPayloadMatchesCommand($created, $command);
        $this->assertSettingsSeededFromCountryDefaults($settings);
    }

    protected function assertCompanyCreated(EloquentStoredEvent $event, string $expectedTrialEndsAt): void
    {
        $this->assertSame(CompanyCreated::class, $event->event_class);
        $this->assertSame($expectedTrialEndsAt, $event->event_properties['trialEndsAt']);
    }

    protected function assertEventPayloadMatchesCommand(EloquentStoredEvent $event, CreateCompanyCommand $command): void
    {
        $this->assertSame($command->name, $event->event_properties['name']);
        $this->assertSame($command->brNumber, $event->event_properties['brNumber']);
        $this->assertSame($command->epfNumber, $event->event_properties['epfNumber']);
        $this->assertSame($command->country, $event->event_properties['country']);
        $this->assertSame($command->ownerUserId, $event->event_properties['ownerUserId']);
    }

    protected function assertSettingsSeededFromCountryDefaults(EloquentStoredEvent $event): void
    {
        $this->assertSame(CompanySettingsCreated::class, $event->event_class);
        $this->assertSame('LKR', $event->event_properties['currency']);
        $this->assertSame('Asia/Colombo', $event->event_properties['timezone']);
        $this->assertSame('en', $event->event_properties['localeDefault']);
    }
}
