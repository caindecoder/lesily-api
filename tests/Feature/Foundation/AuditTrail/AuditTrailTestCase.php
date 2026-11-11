<?php

namespace Tests\Feature\Foundation\AuditTrail;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use Database\Factories\AuditEntryFactory;
use App\Foundation\AuditTrail\Enums\AuditAction;
use Tests\Feature\Foundation\FoundationTestCase;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use Tests\Feature\Foundation\WithRolePermissionFixtures;

abstract class AuditTrailTestCase extends FoundationTestCase
{
    use WithRolePermissionFixtures;

    protected Company $companyA;

    protected Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
        config(['app.debug' => false]);
        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
    }

    protected function aConsentingMember(Company $company, string $roleCode): User
    {
        $member = User::factory()->create();
        $this->attachMember($company, $member, Role::query()->where('code', $roleCode)->sole());
        $this->aConsentRecordedBy($member, $company);

        return $member;
    }

    protected function anEntryOfCompanyAAt(string $recordedAt): AuditEntry
    {
        return AuditEntry::factory()
            ->forCompany($this->companyA)
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
            ->forCompany($this->companyA)
            ->byMember($actor, 'admin')
            ->ofAction(AuditAction::DsarDownloaded, AuditSubjectType::DsarRequest);
    }

    protected function anEntryOfCompanyAFor(AuditAction $action, AuditSubjectType $subjectType): AuditEntry
    {
        return AuditEntry::factory()
            ->forCompany($this->companyA)
            ->byMember(User::factory()->create(), 'admin')
            ->ofAction($action, $subjectType)
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

    protected function sendToAuditTrail(string $verb, User $actor, string $path): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->json($verb, $path, [], ['X-Company-Context' => $this->companyA->getKey()]);
    }

    /**
     * @param  list<string>  $entryIds
     */
    protected function assertEntriesListed(TestResponse $response, array $entryIds): void
    {
        $response->assertOk();
        $this->assertSame($entryIds, $response->json('data.*.id'));
    }

    protected function assertNothingListed(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.pagination.total', 0);
    }

    /**
     * @param  array{page: int, per_page: int, total: int, last_page: int}  $pagination
     */
    protected function assertPageShown(TestResponse $response, array $pagination): void
    {
        $this->assertSame($pagination, $response->json('meta.pagination'));
    }

    protected function assertNoActorShown(TestResponse $response, AuditEntry $entry): void
    {
        $expected = ['actor_id' => null, 'actor_name' => null, 'actor_role' => null, 'actor_role_name' => null];

        $this->assertEntriesListed($response, [$entry->getKey()]);
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

    protected function assertRoleInsufficient(TestResponse $response): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('message', 'You do not have permission to perform this action.');
        $response->assertJsonPath('required_permission', 'audit.view');
    }

    protected function assertValidationRejected(TestResponse $response, string $invalidField, string $expectedMessage): void
    {
        $response->assertUnprocessable();
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors([$invalidField => $expectedMessage]);
    }

    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonMissingPath('data');
    }
}
