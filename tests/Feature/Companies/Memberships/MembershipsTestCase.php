<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\CompanyUser;
use App\Foundation\Billing\Enums\BillingPlan;
use Tests\Feature\Companies\CompaniesTestCase;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Events\UserInvited;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Mail\InvitationMail;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\WithRolePermissionFixtures;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

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
     * Request the roster as $actor acting in $company's context (UC-1-017).
     *
     * @param  array<string, mixed>  $query
     */
    protected function viewMemberships(User $actor, Company $company, array $query = []): TestResponse
    {
        Sanctum::actingAs($actor);

        $queryString = $query === [] ? '' : '?'.http_build_query($query);

        return $this->getJson('/api/v1/memberships'.$queryString, ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * No invitation was persisted, no UserInvited event was recorded, and no
     * invite email was queued — the guard rejected before any side effect.
     */
    protected function assertNoInvitationCreated(): void
    {
        $this->assertDatabaseCount('invitations', 0);
        $this->assertSame(0, LesilyStoredEvent::query()->where('event_class', UserInvited::class)->count());
        Mail::assertNothingQueued();
    }

    /**
     * The request was refused as shape-invalid — the validation envelope
     * (error_code VALIDATION_FAILED) with an error on $field.
     */
    protected function assertValidationRejected(TestResponse $response, string $field): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors($field);
    }

    /**
     * Assert a request was refused with the §S21 envelope for $code (status +
     * error_code). Mirrors AuthTestCase's helper (§2.10).
     */
    protected function assertRefusedWith(TestResponse $response, ApiErrorCode $code): void
    {
        $response->assertStatus($code->status());
        $response->assertJsonPath('error_code', $code->value);
    }

    /**
     * Exhaust the per-actor hourly invite allowance with $allowed valid invites
     * (unique emails, each accepted) — pinning the allowance at exactly $allowed,
     * per AuthTestCase::exhaustAuthLimit. Relies on the array cache resetting the
     * limiter per test method (CACHE_STORE=array).
     */
    protected function exhaustInviteLimit(User $actor, Company $company, int $allowed = 20): void
    {
        for ($i = 0; $i < $allowed; $i++) {
            $this->invite($actor, $company, [
                'email' => sprintf('invitee-%02d@contoso.lk', $i),
                'roleCode' => 'employee',
            ])->assertStatus(201);
        }
    }

    protected function assertThrottled(TestResponse $response): void
    {
        $response->assertStatus(429);
        $response->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED');
        $this->assertGreaterThan(0, (int) $response->json('retry_after'), 'A throttled response must carry retry guidance.');
    }

    /**
     * A membership that was removed (UC-1-024 semantics): the company_user pivot
     * exists but is soft-deleted. Returns the pivot id — the stable membership
     * UUID a re-invite must reuse (D5b undelete-in-place).
     */
    protected function aRemovedMembership(Company $company, User $user): string
    {
        $this->attachMember($company, $user, $this->seededRole('employee'));

        $membershipId = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->sole()
            ->getKey();

        CompanyUser::query()->whereKey($membershipId)->update(['deleted_at' => now()]);

        return $membershipId;
    }

    /**
     * A pending invitation for ($company, $email) is stored the D3 way — the role
     * assigned, not yet accepted, a sha256 hash at rest (never the plaintext),
     * 7-day expiry — and the write was event-sourced: a UserInvited stored event
     * carries this invitation's id (amendment R — a direct read-model write must
     * fail this assertion).
     */
    protected function assertPendingInvitationCreated(Company $company, string $email, string $roleCode): void
    {
        $invitation = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->sole();

        $this->assertSame($this->seededRole($roleCode)->getKey(), $invitation->role_id);
        $this->assertNull($invitation->accepted_at);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $invitation->token_hash);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $invitation->expires_at->getTimestamp(), 10);
        // The event's own payload must match the projected row — proving the
        // projector copied the event verbatim, not a coincidental direct write.
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserInvited::class)
            ->where('event_properties->invitationId', $invitation->getKey())
            ->where('event_properties->companyId', $company->getKey())
            ->where('event_properties->email', $email)
            ->where('event_properties->tokenHash', $invitation->token_hash)
            ->count());
    }

    /**
     * Exactly one InvitationMail was queued to $email, its accept-link carries the
     * plaintext token + the urlencoded email, and that emailed plaintext sha256s to
     * the stored token_hash — the correspondence that makes the accept lookup work
     * (D3; the invitations table holds only the hash).
     */
    protected function assertInvitationMailQueued(Company $company, string $email): void
    {
        Mail::assertQueued(InvitationMail::class, 1);

        $plaintextToken = null;
        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use ($email, &$plaintextToken): bool {
            $this->assertTrue($mail->hasTo($email));
            $this->assertMatchesRegularExpression('/token=[A-Za-z0-9]{40}/', $mail->acceptUrl());
            $this->assertStringContainsString('email='.urlencode($email), $mail->acceptUrl());
            $plaintextToken = $mail->plaintextToken;

            return true;
        });

        $storedHash = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->sole()
            ->token_hash;
        $this->assertSame(hash('sha256', (string) $plaintextToken), $storedHash);
    }
}
