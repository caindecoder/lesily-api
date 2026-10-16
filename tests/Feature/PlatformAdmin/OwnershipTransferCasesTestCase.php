<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Companies\Enums\OwnershipTransferCaseOutcome;

abstract class OwnershipTransferCasesTestCase extends PlatformAdminTestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seedRbacCatalog();

        $this->admin = $this->anAllowlistedAdmin();
    }

    protected function anAllowlistedAdmin(): User
    {
        $admin = User::factory()->create();
        $this->allowlist([$admin->getKey()]);

        return $admin;
    }

    protected function listCases(?User $caller, array $query = []): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        $queryString = $query === [] ? '' : '?'.http_build_query($query);

        return $this->getJson('/api/v1/admin/ownership-transfer-cases'.$queryString);
    }

    protected function showCase(?User $caller, string $caseId): TestResponse
    {
        if ($caller !== null) {
            Sanctum::actingAs($caller);
        }

        return $this->getJson('/api/v1/admin/ownership-transfer-cases/'.$caseId);
    }

    protected function anOpenCaseFor(Company $company, ?User $proposed = null): OwnershipTransferCase
    {
        $factory = OwnershipTransferCase::factory()->forCompany($company);

        if ($proposed !== null) {
            $factory = $factory->proposedOwner($proposed);
        }

        return $factory->create();
    }

    protected function aVerifiedCaseFor(Company $company, ?User $proposed = null): OwnershipTransferCase
    {
        $factory = OwnershipTransferCase::factory()->forCompany($company);

        if ($proposed !== null) {
            $factory = $factory->proposedOwner($proposed);
        }

        return $factory->verified()->create();
    }

    protected function aClosedCaseFor(Company $company, OwnershipTransferCaseOutcome $outcome): OwnershipTransferCase
    {
        $factory = OwnershipTransferCase::factory()->forCompany($company);

        $factory = $outcome === OwnershipTransferCaseOutcome::Executed
            ? $factory->closedExecuted()
            : $factory->closedRejected();

        return $factory->create();
    }

    /**
     * Exactly ONE audit row evidences the case read, subject-pinned to the
     * case and company-attributed (the UC-1-122 detail-read shape), with
     * request-context columns IDENTITY-pinned per the sibling standard.
     */
    protected function assertCaseReadAudited(OwnershipTransferCase $case, User $actor, TestResponse $response): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'OwnershipTransferCaseViewed',
            'subject_type' => 'ownership_transfer_case',
            'subject_id' => $case->getKey(),
            'subject_label' => null,
            'action' => 'case_viewed',
            'description' => 'Platform admin viewed an ownership-transfer case.',
            'company_id' => $case->company_id,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $actor->name,
            'stored_event_id' => null,
            'metadata' => null,
            'severity' => 'info',
            'ip_address' => '127.0.0.1',
        ]);

        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $entry = AuditEntry::query()->sole();
        $this->assertSame($correlationId, $entry->request_id);
        $this->assertSame('Symfony', $entry->user_agent);
    }

    /**
     * The read mutated nothing: status, outcome and updated_at untouched.
     */
    protected function assertCaseUnchanged(OwnershipTransferCase $case): void
    {
        $fresh = $case->fresh();
        $this->assertInstanceOf(OwnershipTransferCase::class, $fresh);
        $this->assertSame($case->status, $fresh->status);
        $this->assertSame($case->closed_outcome, $fresh->closed_outcome);
        $this->assertEquals($case->updated_at, $fresh->updated_at);
    }

    /**
     * The pagination meta is exactly this (api-conventions §8.3 envelope).
     */
    protected function assertCasePage(TestResponse $response, array $meta): void
    {
        $this->assertSame($meta, $response->json('meta.pagination'));
    }

    /**
     * The page lists exactly these cases, in exactly this order.
     */
    protected function assertCasesListedInOrder(TestResponse $response, array $caseIdsInOrder): void
    {
        $response->assertOk();
        $this->assertSame($caseIdsInOrder, $response->json('data.*.id'));
    }

    /**
     * The action failed closed (slice-11R spec §4): a 500 INTERNAL_ERROR
     * envelope carrying no case data at all — for reads, no read without
     * evidence; for writes, the in-transaction audit veto.
     */
    protected function assertFailedClosed(TestResponse $response): void
    {
        $response->assertInternalServerError();
        $response->assertJsonPath('error_code', 'INTERNAL_ERROR');
        $response->assertJsonMissingPath('data');
    }

    /**
     * Exactly ONE audit row evidences the queue read (UC-1-127 postcondition:
     * one row per PAGE, not per case row). A list has no single subject, so
     * subject_id and company_id are NULL and the read's query context
     * (filters, result_count, page) rides metadata — compared
     * order-insensitively, since MySQL canonicalizes JSON object key order.
     * Request-context columns IDENTITY-pinned per the sibling
     * assertCaseOpenAudited standard.
     */
    protected function assertQueueReadAudited(User $actor, TestResponse $response, array $expectedMetadata): void
    {
        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'event_class' => 'OwnershipTransferCaseQueueViewed',
            'subject_type' => 'ownership_transfer_case_queue',
            'subject_id' => null,
            'subject_label' => null,
            'action' => 'case_queue_viewed',
            'description' => 'Platform admin viewed a page of the ownership-transfer case queue.',
            'company_id' => null,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $actor->name,
            'stored_event_id' => null,
            'severity' => 'info',
            'ip_address' => '127.0.0.1',
        ]);

        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $entry = AuditEntry::query()->sole();
        $this->assertEquals($expectedMetadata, $entry->metadata);
        $this->assertSame($correlationId, $entry->request_id);
        $this->assertSame('Symfony', $entry->user_agent);
    }
}
