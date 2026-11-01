<?php

namespace Tests\Feature\Companies;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Aggregates\Company;
use App\Companies\Enums\CompanyStatus;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Events\CompanyCreated;
use App\Companies\Entities\CompanySettings;
use App\Companies\Events\CompanySettingsCreated;
use App\Companies\Entities\Company as CompanyReadModel;
use App\Companies\Exceptions\UnsupportedCountryException;
use App\Companies\UseCases\Commands\CreateCompanyCommand;
use App\Companies\Exceptions\CompanyAlreadyExistsException;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * Create-company genesis at the domain seam (conv-plan §2.14 Rule C): both callers
 * of CreateCompanyInteractor (signup, POST /companies) mint fresh UUIDs, so the
 * re-genesis guard below is unreachable via HTTP — it protects replay integrity and
 * any later caller, and is provable only at this seam.
 */
class CreateCompanyTest extends CompaniesTestCase
{
    use CreatesTrialCompanies;

    // Sad — genesis is illegal from any state but [*]: an existing company cannot
    // be re-created, and the rejection must leave the original genesis untouched.
    #[Test]
    public function when_a_company_that_already_exists_is_created_again_then_it_is_rejected(): void
    {
        // Given a company that was already created
        $companyId = (string) Str::uuid();
        $this->createCompanyOnTrial($companyId);

        // When the same company is created again
        try {
            Company::retrieve($companyId)->create($this->buildCreateCompanyCommand($companyId, 'Contoso Again'));
            $this->fail('Re-creating an existing company must be rejected.');
        } catch (CompanyAlreadyExistsException) {
            // Then it is rejected and the original genesis is untouched
            $this->assertSame(2, LesilyStoredEvent::query()->whereForCompany($companyId)->count());
        }
    }

    // Sad — only launch-market countries have defaults to seed: an unsupported
    // country is rejected at the aggregate seam (the HTTP 422 country rule is the
    // slice-05 guard above it), and the rejection must persist nothing.
    #[Test]
    public function when_a_company_is_created_for_an_unsupported_country_then_it_is_rejected(): void
    {
        // Given an owner-signup command for a country without seeded defaults
        $companyId = (string) Str::uuid();
        $command = $this->buildCreateCompanyCommandForCountry($companyId, 'US');

        // When the company is created
        try {
            Company::retrieve($companyId)->create($command);
            $this->fail('Creating a company for an unsupported country must be rejected.');
        } catch (UnsupportedCountryException) {
            // Then it is rejected and nothing is persisted for the company
            $this->assertSame(0, LesilyStoredEvent::query()->whereForCompany($companyId)->count());
        }
    }

    protected function buildCreateCompanyCommandForCountry(string $companyId, string $country): CreateCompanyCommand
    {
        $command = $this->buildCreateCompanyCommand($companyId);

        return CreateCompanyCommand::from(['country' => $country, 'owner' => $command->owner] + $command->toArray());
    }

    // Happy — genesis: creating a company opens a 30-day trial scoped to its own
    // tenant, carrying the full registration payload (UC-1-031, slice C3/C4),
    // seeding the settings from the country defaults (CD-1-02), projecting the
    // trial row with the owner linked (OQ-1-105), and minting the owner's
    // membership pivot UUID (ERD-1-01, R-042) — one behavioral walk, write side
    // and read side together (conv-plan sec 2.14 Rule A).
    #[Test]
    public function when_a_company_is_created_then_the_genesis_is_stored_and_projected_in_its_own_company_scope_opening_a_30_day_trial(): void
    {
        // Given a fixed point in time, a new company id, and an owner-signup command
        $this->travelTo('2026-06-01 00:00:00');
        $companyId = (string) Str::uuid();
        $command = $this->buildCreateCompanyCommand($companyId);

        // When the company is created and persisted (the projectors run synchronously)
        Company::retrieve($companyId)->create($command)->persist();

        // Then the write side stores exactly the two genesis events, scoped to the
        // company, opening the 30-day trial and mirroring the command...
        $this->assertSame(2, LesilyStoredEvent::query()->whereForCompany($companyId)->count());

        $created = LesilyStoredEvent::query()->whereForCompany($companyId)
            ->where('event_class', CompanyCreated::class)->sole();
        $settings = LesilyStoredEvent::query()->whereForCompany($companyId)
            ->where('event_class', CompanySettingsCreated::class)->sole();

        $this->assertCompanyCreatedEventOpensTrial($created, '2026-07-01T00:00:00+00:00');
        $this->assertEventPayloadMatchesCommand($created, $command);
        $this->assertSettingsSeededFromCountryDefaults($settings);

        // ...and the read side projects the same genesis: the trial row with the
        // owner linked, plus the owner's membership pivot keyed by a UUID
        $row = CompanyReadModel::findOrFail($companyId);

        $this->assertCompanyOnTrial($row, 'Contoso', '2026-07-01 00:00:00');
        $this->assertRegistrationProjected($row, $command);
        $this->assertOwnerMembershipPivotIsUuid($companyId, $command->owner->getKey());
    }

    protected function assertCompanyCreatedEventOpensTrial(EloquentStoredEvent $event, string $expectedTrialEndsAt): void
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
        $this->assertSame($command->owner->getKey(), $event->event_properties['ownerUserId']);
    }

    protected function assertSettingsSeededFromCountryDefaults(EloquentStoredEvent $event): void
    {
        $this->assertSame(CompanySettingsCreated::class, $event->event_class);
        $this->assertSame('LKR', $event->event_properties['currency']);
        $this->assertSame('Asia/Colombo', $event->event_properties['timezone']);
        $this->assertSame('en', $event->event_properties['localeDefault']);
        $this->assertSame(
            $event->event_properties['settingsId'],
            CompanySettings::query()->where('company_id', $event->event_properties['companyId'])->sole()->getKey(),
        );
    }

    protected function assertCompanyOnTrial(CompanyReadModel $row, string $name, string $trialEndsAt): void
    {
        $this->assertSame($name, $row->name);
        $this->assertSame(CompanyStatus::Trial, $row->status);
        $this->assertNull($row->current_billing_plan);
        $this->assertSame($trialEndsAt, $row->trial_ends_at?->toDateTimeString());
    }

    protected function assertRegistrationProjected(CompanyReadModel $row, CreateCompanyCommand $command): void
    {
        $this->assertSame($command->brNumber, $row->br_number);
        $this->assertSame($command->epfNumber, $row->epf_number);
        $this->assertSame($command->country, $row->country);
        $this->assertSame($command->owner->getKey(), $row->owner_id);
    }

    protected function assertOwnerMembershipPivotIsUuid(string $companyId, string $ownerUserId): void
    {
        $membershipId = DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $ownerUserId)
            ->value('id');

        $this->assertIsString($membershipId);
        $this->assertTrue(Str::isUuid($membershipId), 'company_user.id must be a char(36) UUID (ERD-1-01, R-042)');
    }
}
