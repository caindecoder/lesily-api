<?php

namespace Tests\Feature\Compliance\Breach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Compliance\Breach\Events\BreachResolved;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

class ResolveBreachIncidentTest extends BreachIncidentsTestCase
{
    protected const string ROOT_CAUSE = 'A payroll export job sent files without checking the recipient.';

    protected const string REMEDIATION = 'Exports now need a second person to confirm the recipient; the job was patched.';

    protected const string SUMMARY = 'One file reached one outside person, who deleted it; no further reading was found.';

    protected const string PAST_THE_DEADLINE = '2026-05-08T10:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-05-05T09:00:00Z'));
    }

    #[Test]
    public function when_an_unauthenticated_caller_resolves_then_it_is_refused_as_unauthenticated(): void
    {
        // Given an incident whose companies A and B have been notified, its deadline passed
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When a caller with no token resolves it
        $response = $this->resolve(null, $breachId, $this->aResolution());

        // Then they are refused as unauthenticated and nothing is resolved
        $this->assertUnauthenticated($response);
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test]
    public function when_a_company_owner_resolves_then_the_admin_surface_is_not_acknowledged(): void
    {
        // Given an incident whose companies A and B have been notified, its deadline passed, and company A's owner
        $breachId = $this->aNotifiedIncident($this->companyA, $this->companyB);
        $owner = $this->anOwnerOf($this->companyA);
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When the owner resolves it on the admin surface
        $response = $this->resolve($owner, $breachId, $this->aResolution());

        // Then the admin surface is not acknowledged and nothing is resolved
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertNothingResolved($breachId, $before);
    }

    #[Test, DataProvider('provideUnknownBreachIds')]
    public function when_the_incident_does_not_exist_then_it_is_not_found(string $breachId): void
    {
        // Given an admin and no incident with this id

        // When the admin resolves it
        $response = $this->resolve($this->admin, $breachId, $this->aResolution());

        // Then it is not found and nothing is recorded
        $this->assertResourceNotFoundNoLeak($response);
        $this->assertSame(0, LesilyStoredEvent::query()->count());
    }

    #[Test, DataProvider('provideInvalidResolutions')]
    public function when_the_resolution_is_invalid_then_it_is_refused_and_nothing_is_recorded(array $override, string $field, string $message): void
    {
        // Given an incident whose company A has been notified, its deadline passed
        $breachId = $this->aNotifiedIncident($this->companyA);
        $before = $this->snapshotOf($breachId);
        $this->travelTo(CarbonImmutable::parse(self::PAST_THE_DEADLINE));

        // When the admin resolves it with one fact wrong
        $response = $this->resolve($this->admin, $breachId, $this->aResolution($override));

        // Then the close is refused on that fact and nothing is resolved
        $this->assertValidationRejected($response, $field, $message);
        $this->assertNothingResolved($breachId, $before);
    }

    public static function provideInvalidResolutions(): array
    {
        return [
            'no root cause' => [['rootCause' => null], 'rootCause', 'The root cause field is required.'],
            'a root cause that is not text' => [['rootCause' => ['a list']], 'rootCause', 'The root cause field must be a string.'],
            'a root cause over 1,000 characters' => [['rootCause' => str_repeat('a', 1001)], 'rootCause', 'The root cause field must not be greater than 1000 characters.'],
            'no remediation steps' => [['remediationSteps' => null], 'remediationSteps', 'The remediation steps field is required.'],
            'remediation steps that are not text' => [['remediationSteps' => ['a list']], 'remediationSteps', 'The remediation steps field must be a string.'],
            'remediation steps over 1,000 characters' => [['remediationSteps' => str_repeat('a', 1001)], 'remediationSteps', 'The remediation steps field must not be greater than 1000 characters.'],
            'no summary' => [['summary' => null], 'summary', 'The summary field is required.'],
            'a summary that is not text' => [['summary' => ['a list']], 'summary', 'The summary field must be a string.'],
            'a summary over 1,000 characters' => [['summary' => str_repeat('a', 1001)], 'summary', 'The summary field must not be greater than 1000 characters.'],
        ];
    }

    protected function resolve(?User $caller, string $breachId, array $body): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->patchJson("/api/v1/admin/breach-incidents/{$breachId}/resolve", $body);
    }

    protected function aResolution(array $overrides = []): array
    {
        return [
            'rootCause' => self::ROOT_CAUSE,
            'remediationSteps' => self::REMEDIATION,
            'summary' => self::SUMMARY,
            ...$overrides,
        ];
    }

    protected function assertNothingResolved(string $breachId, array $before): void
    {
        $this->assertSame($before, $this->snapshotOf($breachId));
        $this->assertSame(0, LesilyStoredEvent::query()->where('aggregate_uuid', $breachId)->where('event_class', BreachResolved::class)->count());
        $this->assertDatabaseCount('audit_trail', 0);
    }
}
