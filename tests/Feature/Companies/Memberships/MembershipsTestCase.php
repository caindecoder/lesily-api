<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Auth;
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
use App\Companies\Memberships\Events\UserJoinedCompany;
use Tests\Feature\Foundation\WithRolePermissionFixtures;
use App\Foundation\Auth\Events\UserCreatedFromInvitation;
use App\Companies\Memberships\Events\UserInvitationRevoked;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
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
     * The roster lists exactly the members whose user ids are $expectedUserIds —
     * set equality on data.*.user.id (order-insensitive; ViewSessionsTest pattern).
     *
     * @param  array<int, string>  $expectedUserIds
     */
    protected function assertRosterUserIds(TestResponse $response, array $expectedUserIds): void
    {
        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            $expectedUserIds,
            $response->collect('data')->pluck('user.id')->all()
        );
    }

    /**
     * $user appears in the roster with the full contract row shape (slice §2):
     * a membership id, the user sub-object, the role {code, name}, and the
     * derived status trio (status 'active'; invited_at/last_active_at null until
     * their sources land — Task 3+, declared s108).
     */
    protected function assertMembershipListed(TestResponse $response, User $user, string $roleCode): void
    {
        $response->assertOk();

        /** @var array<string, mixed>|null $row */
        $row = $response->collect('data')->firstWhere('user.id', $user->getKey());

        $this->assertNotNull($row, "No roster row for {$user->email}.");
        $this->assertNotEmpty($row['id'], 'A roster row must carry the membership id.');
        $this->assertSame(['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email], $row['user']);
        $this->assertSame($roleCode, $row['role']['code']);
        $this->assertSame($this->seededRole($roleCode)->name, $row['role']['name']);
        $this->assertSame(['active', null, null], [$row['status'], $row['invited_at'], $row['last_active_at']]);
    }

    /**
     * The roster rows appear exactly in $namesInOrder (data.*.user.name) —
     * null entries are invited rows, whose user has no name (s110 R4a).
     *
     * @param  array<int, string|null>  $namesInOrder
     */
    protected function assertRosterOrder(TestResponse $response, array $namesInOrder): void
    {
        $response->assertOk();
        $this->assertSame($namesInOrder, $response->collect('data')->pluck('user.name')->all());
    }

    /**
     * The meta.pagination block matches $expected exactly — the api-conventions
     * §8.3 list envelope {page, per_page, total, last_page}.
     *
     * @param  array{page: int, per_page: int, total: int, last_page: int}  $expected
     */
    protected function assertPaginationMeta(TestResponse $response, array $expected): void
    {
        $response->assertOk();
        $this->assertSame($expected, $response->json('meta.pagination'));
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
     * Accept an invitation via its plaintext token — as a guest by default, or
     * authenticated when $as is given (the existing-user branch, R9/D7).
     *
     * @param  array<string, mixed>  $body
     */
    protected function acceptInvitation(string $token, array $body = [], ?User $as = null): TestResponse
    {
        if ($as instanceof User) {
            Sanctum::actingAs($as);
        }

        return $this->postJson('/api/v1/invitations/accept/'.$token, $body);
    }

    /**
     * A valid new-user acceptance body — name + a policy-passing password (H6:
     * the token rides the route; the invitee email lives on the invitation row).
     *
     * @return array<string, string>
     */
    protected function validNewUserBody(): array
    {
        return ['name' => 'Nimal Perera', 'password' => 'Str0ng-Pass!'];
    }

    /**
     * A refused accept changed nothing: no user account was created, no
     * membership pivot was written, and no invitation was marked accepted —
     * the guard rejected before any state change.
     */
    protected function assertNothingAccepted(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertSame(0, Invitation::query()->whereNotNull('accepted_at')->count());
    }

    /**
     * Issue a REAL pending invitation for ($company, $email) through the invite
     * endpoint and return the PLAINTEXT accept token, extracted from the faked
     * InvitationMail (the DB holds only the sha256 — D3). Ends with a guard-cache
     * reset (amendment P) so the arrange leaves no actingAs residue and the
     * accept under test runs as a genuine guest unless it authenticates itself.
     */
    protected function aPendingInvitation(Company $company, User $actor, string $email): string
    {
        $this->invite($actor, $company, ['email' => $email, 'roleCode' => 'employee'])->assertStatus(201);

        $plaintextToken = null;
        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use ($email, &$plaintextToken): bool {
            if ($mail->hasTo($email)) {
                $plaintextToken = $mail->plaintextToken;
            }

            return true;
        });
        $this->assertNotNull($plaintextToken, "No InvitationMail was queued to {$email}.");

        Auth::forgetGuards();

        return (string) $plaintextToken;
    }

    /**
     * Issue a pending invitation for ($company, $email) and REVOKE it through the
     * DELETE endpoint acting as $actor (who must hold users.revoke-invitation).
     * Returns the ORIGINAL plaintext token — now dead — so consumers can prove a
     * revoked token no longer accepts/resends. Ends guard-clean (amendment P).
     */
    protected function aRevokedInvitation(Company $company, User $actor, string $email): string
    {
        $plaintextToken = $this->aPendingInvitation($company, $actor, $email);
        $invitationId = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->value('id');

        Sanctum::actingAs($actor);
        $this->deleteJson(
            "/api/v1/invitations/{$invitationId}",
            [],
            ['X-Company-Context' => $company->getKey()]
        )->assertNoContent();

        Auth::forgetGuards();

        return $plaintextToken;
    }

    /**
     * The membership for ($company, $email) was ACTIVATED the D5b way: the
     * pivot carries the SAME UUID as the invitation (id AND invitation_id), is
     * live under the invitation's role, the invitation is stamped accepted —
     * and the write was event-sourced: a UserJoinedCompany stored event carries
     * this membership's ids verbatim (amendment R — a direct read-model write
     * must fail this assertion).
     */
    protected function assertMembershipActivated(Company $company, string $email): void
    {
        $invitation = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->sole();
        $membership = CompanyUser::query()->whereKey($invitation->getKey())->sole();

        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame($invitation->getKey(), $membership->invitation_id);
        $this->assertSame([$invitation->role_id, null], [$membership->role_id, $membership->deleted_at]);
        // The welcome-wizard flags start false on every creation path — the
        // schema defaults are the mechanism (H3 session 109).
        $this->assertSame([0, 0], [(int) $membership->onboarding_completed, (int) $membership->onboarding_skipped]);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserJoinedCompany::class)
            ->where('event_properties->membershipId', $invitation->getKey())
            ->where('event_properties->userId', $membership->user_id)
            ->where('event_properties->roleId', $invitation->role_id)
            ->count());
    }

    /**
     * The invite-accept genesis provisioned the account the event-sourced way:
     * a users row under $name with a set password, born from a
     * UserCreatedFromInvitation stored event whose payload matches the row
     * verbatim (amendment R).
     */
    protected function assertNewUserProvisioned(string $email, string $name): void
    {
        $user = User::query()->where('email', $email)->sole();

        $this->assertSame($name, $user->name);
        $this->assertNotSame('', $user->password, 'The provisioned account must carry a set password.');
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserCreatedFromInvitation::class)
            ->where('event_properties->userId', $user->getKey())
            ->where('event_properties->email', $email)
            ->where('event_properties->name', $name)
            ->count());
    }

    /**
     * The accept response carries a REAL credential: its data.token drives an
     * auth+company-gated probe for $company. Guard cache reset first — the
     * accept request itself resolved a guard (amendment P).
     */
    protected function assertAutoLoggedIn(TestResponse $response, Company $company): void
    {
        $token = (string) $response->json('data.token');
        $this->assertNotSame('', $token, 'The accept response must carry a session token.');

        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/_test/company-context-probe', ['X-Company-Context' => $company->getKey()])
            ->assertOk();
    }

    /**
     * The post-accept welcome (D8/R8) reached the joiner: one in-app
     * notification row typed team_welcome carrying the inviting company's name.
     */
    protected function assertTeamWelcomeQueued(string $email, Company $company): void
    {
        $joiner = User::query()->where('email', $email)->sole();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $joiner->getKey(),
            'data->notification_type' => 'team_welcome',
            'data->company_name' => $company->name,
        ]);
        // The mail leg is queued too (TeamWelcome is non-critical, no opt-out
        // exists in these tests) — pins the QueuedMailChannel wiring.
        Mail::assertQueued(SystemNotificationMail::class, fn (SystemNotificationMail $mail): bool => $mail->hasTo($email));
    }

    /**
     * The invitation for ($company, $email) was NOT consumed: it remains
     * un-accepted and no membership pivot (live or trashed) exists for that
     * email's user in the company.
     */
    /**
     * The invitation was revoked the event-sourced way: revoked_at stamped now
     * (±10s), revoked_by_user_id = $revokedBy, no reason by default, exactly one
     * UserInvitationRevoked stored event carrying this row's id + actor verbatim
     * (amendment R — a direct read-model write must fail this), and no mail
     * beyond the arrange-time invite (revoke sends nothing).
     */
    protected function assertInvitationRevoked(Company $company, string $email, User $revokedBy): void
    {
        $invitation = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->sole();

        $this->assertNotNull($invitation->revoked_at);
        $this->assertEqualsWithDelta(now()->getTimestamp(), $invitation->revoked_at->getTimestamp(), 10);
        $this->assertSame($revokedBy->getKey(), $invitation->revoked_by_user_id);
        $this->assertNull($invitation->revocation_reason);

        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', UserInvitationRevoked::class)
            ->where('event_properties->membershipId', $invitation->getKey())
            ->where('event_properties->revokedBy', $revokedBy->getKey())
            ->count());

        Mail::assertQueued(InvitationMail::class, 1);
    }

    /**
     * The invitation was NOT revoked: all three revocation columns are untouched
     * — sad paths change nothing (the LD DeletePersonalAccessToken
     * state-unchanged discipline).
     */
    protected function assertInvitationNotRevoked(Company $company, string $email): void
    {
        $invitation = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->sole();

        $this->assertNull($invitation->revoked_at);
        $this->assertNull($invitation->revoked_by_user_id);
        $this->assertNull($invitation->revocation_reason);
    }

    protected function assertInvitationNotConsumed(Company $company, string $email): void
    {
        $invitation = Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->sole();

        $this->assertNull($invitation->accepted_at);
        $this->assertSame(0, CompanyUser::withTrashed()
            ->where('company_id', $company->getKey())
            ->whereIn('user_id', User::query()->where('email', $email)->pluck('id'))
            ->count());
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
