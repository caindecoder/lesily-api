<?php

namespace Tests\Feature\Companies;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use App\Consent\Entities\ConsentRecord;
use App\Consent\Events\ConsentRecorded;
use App\Companies\Events\CompanyCreated;
use Illuminate\Database\Migrations\Migration;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * UC-1-069 — consents recorded before the consent store existed are copied into
 * it by the data migration, each once.
 */
class FillConsentStoreFromRecordedConsentsTest extends CompaniesTestCase
{
    use CreatesTrialCompanies;

    protected const string FILL_MIGRATION = '2026_10_26_152314_fill_consent_records_from_recorded_consents.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
    }

    #[Test]
    public function when_no_consent_was_ever_recorded_then_the_fill_adds_nothing(): void
    {
        // Given a company whose only recorded events are its genesis
        $this->createCompanyOnTrial(Str::uuid7()->toString());

        // When the fill migration runs
        $this->runFillMigration();

        // Then the consent store stays empty, beside the genesis the fill passed over
        $this->assertDatabaseCount('consent_records', 0);
        $this->assertSame(1, LesilyStoredEvent::query()->where('event_class', CompanyCreated::class)->count());
    }

    #[Test]
    public function when_the_fill_runs_again_then_no_consent_is_copied_twice(): void
    {
        // Given an owner's consents that the fill migration has already copied
        $this->anOwnerWhoConsentedAt('2026-06-08 09:00:00');
        DB::table('consent_records')->delete();
        $this->runFillMigration();
        $copied = $this->consentRowsWithIds();

        // When the fill migration runs again
        $this->runFillMigration();

        // Then the store holds the same two rows, ids included
        $this->assertCount(2, $copied);
        $this->assertSame($copied, $this->consentRowsWithIds());
    }

    #[Test]
    public function when_consents_were_recorded_before_the_store_then_the_fill_copies_each_with_its_time_and_ip(): void
    {
        // Given two owners who consented in their own companies on different days,
        // before the consent store held anything
        [$ownerA, $companyA] = $this->anOwnerWhoConsentedAt('2026-06-08 09:00:00');
        [$ownerB, $companyB] = $this->anOwnerWhoConsentedAt('2026-06-09 10:30:00');
        DB::table('consent_records')->delete();

        // When the fill migration runs
        $this->runFillMigration();

        // Then each recorded consent is in the store once, with its own time and IP
        $eventIds = $this->consentEventIds();
        $this->assertSame([
            [$eventIds[0], $ownerA->getKey(), $companyA->getKey(), 'tos', 'v1.0', '198.51.100.4', '2026-06-08 09:00:00'],
            [$eventIds[1], $ownerA->getKey(), $companyA->getKey(), 'privacy', 'v1.0', '198.51.100.4', '2026-06-08 09:00:00'],
            [$eventIds[2], $ownerB->getKey(), $companyB->getKey(), 'tos', 'v1.0', '198.51.100.4', '2026-06-09 10:30:00'],
            [$eventIds[3], $ownerB->getKey(), $companyB->getKey(), 'privacy', 'v1.0', '198.51.100.4', '2026-06-09 10:30:00'],
        ], $this->storedConsents());
    }

    /**
     * @return array{User, Company}
     */
    protected function anOwnerWhoConsentedAt(string $time): array
    {
        $this->travelTo($time);
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        return [$owner, $company];
    }

    /**
     * @return array<int, mixed>
     */
    protected function consentEventIds(): array
    {
        return LesilyStoredEvent::query()
            ->where('event_class', ConsentRecorded::class)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function storedConsents(): array
    {
        return ConsentRecord::query()
            ->orderBy('stored_event_id')
            ->get()
            ->map(fn (ConsentRecord $record): array => [
                $record->stored_event_id,
                $record->user_id,
                $record->company_id,
                $record->policy_type,
                $record->version,
                $record->ip_address,
                $record->consented_at->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function consentRowsWithIds(): array
    {
        return ConsentRecord::query()
            ->orderBy('stored_event_id')
            ->get()
            ->map(fn (ConsentRecord $record): array => $record->getAttributes())
            ->all();
    }

    protected function runFillMigration(): void
    {
        $migration = require database_path('migrations/'.self::FILL_MIGRATION);

        if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
            $this->fail('The fill migration must return a migration with up().');
        }

        $migration->up();
    }
}
