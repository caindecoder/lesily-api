<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use App\Foundation\Billing\Enums\BillingPlan;
use Tests\Feature\Companies\CompaniesTestCase;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\WithRolePermissionFixtures;

/**
 * Shared fixtures for the membership (team-invite) feature tests (§2.10). Callers
 * compose a company + a member holding a SEEDED role (a factory role carries no
 * permission grants, so the RBAC catalog is seeded here), then drive the invite
 * endpoint through the actor's company context.
 */
abstract class MembershipsTestCase extends CompaniesTestCase
{
    use RefreshDatabase;
    use WithRolePermissionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeded roles carry the real permission grants the gate reads.
        $this->seedRbacCatalog();
        // Error-path render parity (amendment M): APP_DEBUG=true (the local default)
        // masks the prod render path; the 422/403/429 rejections must render their
        // mapped envelopes the way CI/prod do.
        config(['app.debug' => false]);
        Mail::fake();
    }

    /**
     * A company on $plan with $actor attached under the SEEDED $roleCode.
     *
     * @return array{0: Company, 1: User}
     */
    protected function aCompanyWithMember(string $roleCode = 'admin', BillingPlan $plan = BillingPlan::Pro): array
    {
        $company = Company::factory()->onPlan($plan)->create();
        $actor = User::factory()->create();
        $this->attachMember($company, $actor, $this->seededRole($roleCode));

        return [$company, $actor];
    }

    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * Invite $body as $actor acting in $company's context.
     *
     * @param  array<string, mixed>  $body
     */
    protected function invite(User $actor, Company $company, array $body): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->postJson('/api/v1/invitations', $body, ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * No invitation was persisted and no invite email was queued — the guard
     * rejected before any side effect.
     */
    protected function assertNoInvitationCreated(): void
    {
        $this->assertDatabaseCount('invitations', 0);
        Mail::assertNothingQueued();
    }

    /**
     * The invite was refused as shape-invalid — the validation envelope
     * (error_code VALIDATION_FAILED) with an error on $field.
     */
    protected function assertInviteValidationRejected(TestResponse $response, string $field): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors($field);
    }
}
