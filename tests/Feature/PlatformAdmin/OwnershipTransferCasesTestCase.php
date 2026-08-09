<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\OwnershipTransferCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;

abstract class OwnershipTransferCasesTestCase extends PlatformAdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->seedRbacCatalog();
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

    /**
     * The read failed closed (no read without evidence, slice-11R spec §4):
     * a 500 INTERNAL_ERROR envelope carrying no case data at all.
     */
    protected function assertReadFailedSafely(TestResponse $response): void
    {
        $response->assertStatus(500);
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

        $entry = AuditEntry::query()->sole();
        $this->assertEquals($expectedMetadata, $entry->metadata);
        $this->assertSame($response->headers->get('Correlation-Id'), $entry->request_id);
        $this->assertNotNull($entry->user_agent);
    }
}
