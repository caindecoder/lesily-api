<?php

namespace Tests\Feature\Foundation\AuditTrail;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
use Illuminate\Testing\TestResponse;
use Database\Factories\AuditEntryFactory;
use App\Foundation\AuditTrail\Enums\AuditAction;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;

abstract class AuditTrailTestCase extends FoundationTestCase
{
    use FreeTierCompany;

    protected Company $contosoCompany;

    protected Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        $this->travelTo('2026-11-15 14:30:00');
        $this->makeFreeTierCompany();
        $this->contosoCompany = $this->company;
        $this->otherCompany = Company::factory()->create();
    }

    protected function anEntryOfCompanyAAt(string $recordedAt): AuditEntry
    {
        return AuditEntry::factory()
            ->forCompany($this->contosoCompany)
            ->byMember(User::factory()->create(), 'admin')
            ->recordedAt(CarbonImmutable::parse($recordedAt))
            ->create();
    }

    /**
     * A download of a data request in company A by $actor — each test overrides what it varies.
     */
    protected function anEntryBy(User $actor): AuditEntryFactory
    {
        return AuditEntry::factory()
            ->forCompany($this->contosoCompany)
            ->byMember($actor, 'admin')
            ->ofAction(AuditAction::DsarDownloaded, AuditSubjectType::DsarRequest);
    }

    protected function anEntryOfCompanyAFor(AuditAction $auditAction, AuditSubjectType $auditSubjectType): AuditEntry
    {
        return AuditEntry::factory()
            ->forCompany($this->contosoCompany)
            ->byMember(User::factory()->create(), 'admin')
            ->ofAction($auditAction, $auditSubjectType)
            ->create();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function viewAuditTrail(?User $actor, Company $company, array $query = []): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->getJson('/api/v1/audit-trail?'.http_build_query($query), ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function viewAuditLogs(?User $actor, Company $company, array $query = []): TestResponse
    {
        if ($actor !== null) {
            Sanctum::actingAs($actor);
        }

        return $this->getJson('/api/v1/audit-logs?'.http_build_query($query), ['X-Company-Context' => $company->getKey()]);
    }

    protected function sendToAuditTrail(string $verb, User $actor, string $path): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->json($verb, $path, [], ['X-Company-Context' => $this->contosoCompany->getKey()]);
    }

    /**
     * @param  list<string>  $entryIds
     */
    protected function assertEntriesListed(TestResponse $response, array $entryIds): void
    {
        $response->assertOk();
        $this->assertSame($entryIds, $response->json('data.*.id'));
    }

    /**
     * @param  array<string, mixed>  $expected  the fields to check, in the row's order
     */
    protected function assertRowShows(TestResponse $response, int $position, array $expected): void
    {
        $row = $response->json("data.{$position}");

        $this->assertIsArray($row);
        $this->assertSame($expected, array_intersect_key($row, $expected));
    }

    /**
     * Rows that share a time are listed by id, newest first.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    protected function newestIdFirst(array $ids): array
    {
        rsort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Contoso's creation rows in the audit trail's order — written in one frozen second, so newest id first.
     *
     * @return list<string>
     */
    protected function readyMadeEntryIds(): array
    {
        /** @var list<string> $ids */
        $ids = AuditEntry::query()
            ->where('company_id', $this->company->getKey())
            ->whereIn('action', [AuditAction::CompanyCreated, AuditAction::EmergencyContactUpdated, AuditAction::ConsentRecorded])
            ->pluck('id')
            ->all();

        return $this->newestIdFirst($ids);
    }

    protected function assertListStartsOn(TestResponse $response, string $date): void
    {
        $from = $response->json('meta.retention.from');

        $this->assertIsString($from);
        $this->assertSame($date, $from);
    }

    protected function anAccessRowOfCompanyAAt(string $lastSeenAt): AccessLogEntry
    {
        return AccessLogEntry::factory()
            ->forCompany($this->contosoCompany)
            ->byUser(User::factory()->create())
            ->seenAt(CarbonImmutable::parse($lastSeenAt))
            ->create();
    }

    protected function aSecurityEntryOfCompanyAAt(string $recordedAt): AuditEntry
    {
        $actor = User::factory()->create();

        return AuditEntry::factory()
            ->forCompany($this->contosoCompany)
            ->byMember($actor, 'admin')
            ->ofAction(AuditAction::PasswordChanged, AuditSubjectType::User)
            ->recordedAt(CarbonImmutable::parse($recordedAt))
            ->create();
    }

    /**
     * @param  array{page: int, per_page: int, total: int, last_page: int}  $pagination
     */
    protected function assertPageShown(TestResponse $response, array $pagination): void
    {
        $this->assertSame($pagination, $response->json('meta.pagination'));
    }

    protected function assertNoActorShown(TestResponse $response, AuditEntry $auditEntry): void
    {
        $expected = ['actor_id' => null, 'actor_name' => null, 'actor_role' => null, 'actor_role_name' => null];

        $this->assertEntriesListed($response, [$auditEntry->getKey(), ...$this->readyMadeEntryIds()]);
        $this->assertSame($expected, array_intersect_key($response->json('data.0'), $expected));
    }

    protected function assertStaffShownAsLesilySupport(TestResponse $response): void
    {
        $expected = [
            'actor_id' => null,
            'actor_name' => 'Lesily support',
            'actor_role' => null,
            'actor_role_name' => null,
            'ip_address' => null,
            'device' => null,
        ];

        $response->assertOk();
        $this->assertSame($expected, array_intersect_key($response->json('data.0'), $expected));
    }
}
