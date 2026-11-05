<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Tests\Concerns\AssertsFilterEmptyValueRejection;
use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\UseCases\Commands\BreachIncidentAdminFilters;

class ReviewBreachIncidentQueueTest extends BreachIncidentsTestCase
{
    use AssertsFilterEmptyValueRejection;

    #[Test]
    public function when_an_unauthenticated_caller_opens_the_queue_then_they_are_refused(): void
    {
        // Given a recorded incident
        $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->createOne();

        // When a caller with no token opens the queue
        $response = $this->listQueue(null);

        // Then they are refused as unauthenticated and no read is recorded
        $this->assertUnauthenticated($response);
        $this->assertNoQueueReadRecorded();
    }

    #[Test]
    public function when_a_company_owner_opens_the_queue_then_it_is_not_found_and_nothing_is_audited(): void
    {
        // Given an incident naming company A, and company A's owner, who is not a platform admin
        $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->createOne();
        $owner = $this->aCompanyMember($this->companyA, 'owner');

        // When the owner opens the queue
        $response = $this->listQueue($owner);

        // Then the admin surface is not acknowledged — not even their own company's incident — and no read is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $response->assertJsonMissingPath('data');
        $this->assertNoQueueReadRecorded();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[Test]
    #[DataProvider('provideInvalidQueueQueries')]
    public function when_the_filter_is_invalid_then_it_is_refused(array $query, string $field, string $message): void
    {
        // Given a recorded incident
        $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->createOne();

        // When the admin opens the queue with this filter
        $response = $this->listQueue($this->admin, $query);

        // Then the filter is refused on its field and no read is recorded
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNoQueueReadRecorded();
    }

    #[Test]
    public function when_the_audit_row_cannot_be_written_then_the_queue_is_not_shown(): void
    {
        // Given an incident, and an audit write that fails after its row is inserted —
        // only the read's transaction can take the row back out
        $this->anIncident()->withCompany($this->companyA, ['pii'], 40)->createOne();
        AuditEntry::created(fn () => throw new RuntimeException('induced audit failure'));

        // When the admin opens the queue
        $response = $this->listQueue($this->admin);

        // Then the read fails closed — no incident data, and the evidence row was rolled back with it
        $this->assertFailedClosed($response);
        $this->assertNoQueueReadRecorded();
    }

    #[Test]
    public function when_no_incident_is_recorded_then_an_empty_page_is_shown_and_the_read_is_audited(): void
    {
        // Given no incident anywhere
        // When the admin opens the queue
        $response = $this->listQueue($this->admin);

        // Then the page is empty, and the read still left its evidence
        $this->assertListed($response, []);
        $response->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]);
        $this->assertQueueReadAudited($response, ['filters' => [], 'result_count' => 0, 'page' => 1]);
    }

    #[Test]
    #[DataProvider('provideEmptyFilterValues')]
    public function when_a_breach_filter_is_built_with_an_empty_value_then_it_is_rejected(string $field, string $value): void
    {
        // Given an empty value for a whitelisted filter key — reachable only at the DTO seam
        // (TrimStrings + ConvertEmptyStringsToNull neutralise it over HTTP)

        // When the filter is built directly, then construction is rejected on that field
        $this->assertEmptyFilterValueRejected(BreachIncidentAdminFilters::class, $field, $value);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidQueueQueries(): array
    {
        return [
            'unknown key' => [['sort' => 'deadline'], 'sort', 'This filter is not supported.'],
            'unknown stage' => [['stage' => 'pending'], 'stage', 'The selected stage is invalid.'],
            'array-valued stage' => [['stage' => ['recorded']], 'stage', 'The stage field must be a string.'],
            'per_page below floor' => [['per_page' => 0], 'per_page', 'The per page field must be at least 1.'],
            'per_page above cap' => [['per_page' => 101], 'per_page', 'The per page field must not be greater than 100.'],
            'per_page not a number' => [['per_page' => 'abc'], 'per_page', 'The per page field must be an integer.'],
            'page below floor' => [['page' => 0], 'page', 'The page field must be at least 1.'],
            'page not a number' => [['page' => 'abc'], 'page', 'The page field must be an integer.'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmptyFilterValues(): array
    {
        return [
            'empty stage' => ['stage', ''],
            'empty page' => ['page', ''],
            'empty per_page' => ['per_page', ''],
            'whitespace stage' => ['stage', ' '],
            'whitespace page' => ['page', ' '],
            'whitespace per_page' => ['per_page', ' '],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function listQueue(?User $caller, array $query = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson('/api/v1/admin/breach-incidents?'.http_build_query($query));
    }

    /**
     * @param  list<BreachIncidentRecord>  $incidents
     */
    protected function assertListed(TestResponse $response, array $incidents): void
    {
        $response->assertOk();
        $this->assertSame(
            array_map(fn (BreachIncidentRecord $incident): string => $incident->getKey(), $incidents),
            array_column($response->json('data'), 'id'),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function assertQueueReadAudited(TestResponse $response, array $metadata): void
    {
        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $entry = AuditEntry::query()->where('action', 'breach_queue_viewed')->sole();

        $this->assertDatabaseHas('audit_trail', [
            'id' => $entry->getKey(),
            'company_id' => null,
            'stored_event_id' => null,
            'event_class' => 'BreachIncidentQueueViewed',
            'subject_type' => 'breach_incident_queue',
            'subject_id' => null,
            'subject_label' => null,
            'description' => 'Platform admin viewed a page of the breach queue.',
            'actor_user_id' => $this->admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $this->admin->name,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'request_id' => $correlationId,
            'severity' => 'info',
        ]);
        /** @var array{filters: array<string, mixed>} $stored */
        $stored = $entry->metadata;
        $this->assertEquals($metadata, $stored);
        $this->assertContainsOnlyString($stored['filters']);
    }

    protected function assertNoQueueReadRecorded(): void
    {
        $this->assertDatabaseMissing('audit_trail', ['action' => 'breach_queue_viewed']);
    }
}
