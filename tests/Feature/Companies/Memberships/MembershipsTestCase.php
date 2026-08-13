<?php

namespace Tests\Feature\Companies\Memberships;

use Throwable;
use App\Models\User;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Laravel\Sanctum\NewAccessToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Config;
use App\Companies\Entities\CompanyUser;
use Spatie\EventSourcing\Projectionist;
use App\Foundation\Billing\Enums\BillingPlan;
use Illuminate\Database\Events\QueryExecuted;
use Tests\Feature\Companies\CompaniesTestCase;
use App\Foundation\Auth\UseCases\SessionIssuer;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Events\UserInvited;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Mail\InvitationMail;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\TransactionBeginning;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use App\Companies\Memberships\Events\UserJoinedCompany;
use Tests\Feature\Foundation\WithRolePermissionFixtures;
use App\Foundation\Auth\Events\UserCreatedFromInvitation;
use App\Companies\Memberships\Events\UserInvitationRevoked;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Foundation\Notifications\Mail\SystemNotificationMail;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Companies\Memberships\UseCases\Interactors\AcceptInvitationInteractor;

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
     * Remove membership $membershipId as $actor acting in $company's context
     * (UC-1-024). No body — the membership id rides the route (M1 parity).
     */
    protected function removeMember(User $actor, Company $company, string $membershipId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->deleteJson(
            "/api/v1/memberships/{$membershipId}",
            [],
            ['X-Company-Context' => $company->getKey()]
        );
    }

    /**
     * Mint a production-faithful active session for $user (explicit ~90-day expiry,
     * as SessionIssuer stamps it). Returns the NewAccessToken so callers get both
     * the plaintext (to authenticate) and the id. Mirrors AuthTestCase::aSessionFor.
     */
    protected function aSessionFor(User $user, string $name = 'web'): NewAccessToken
    {
        return $user->createToken($name, ['*'], now()->addMinutes(Config::integer('sanctum.expiration')));
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
     * Run $act recording every SQL statement with the transaction depth at the
     * moment it executed. DB::listen sees what getQueryLog cannot: the log
     * stores text only, and BEGIN/SAVEPOINT never enter it — so depth is the
     * only observable that distinguishes a held lock from an autocommit one.
     * `transactions` counts only TOP-LEVEL begins (event-time level exactly one
     * above ambient) — interior savepoints (e.g. createOrFirst's) are not a
     * second transaction. The count is REQUEST-WIDE: every top-level begin
     * during $act, not just the interactor under test.
     *
     * @return array{ambient: int, queries: array<int, array{sql: string, bindings: array<int, mixed>, depth: int}>, transactions: int}
     */
    protected function recordedQueriesDuring(callable $act): array
    {
        $ambient = DB::transactionLevel();
        $queries = [];
        $transactionsBegun = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower($query->sql),
                'bindings' => $query->bindings,
                'depth' => DB::transactionLevel(),
            ];
        });
        Event::listen(TransactionBeginning::class, function () use (&$transactionsBegun, $ambient): void {
            if (DB::transactionLevel() === $ambient + 1) {
                $transactionsBegun++;
            }
        });

        $act();

        return ['ambient' => $ambient, 'queries' => $queries, 'transactions' => $transactionsBegun];
    }

    /**
     * The owner-guard decided under the held lock (OQ-1-158): a `select … for
     * update` on $table ran inside a transaction DEEPER than the ambient one
     * (an autocommit lock releases the instant its statement returns), the
     * owner-guard's roles read (the 'owner'-bound query) ran AFTER that locked
     * read, and the write followed it inside the same transaction window.
     *
     * @param  array{ambient: int, queries: array<int, array{sql: string, bindings: array<int, mixed>, depth: int}>, transactions: int}  $capture
     */
    protected function assertOwnerGuardDecidedUnderLock(array $capture, string $table): void
    {
        $lockIndex = null;
        $guardIndex = null;
        $writeIndex = null;

        foreach ($capture['queries'] as $index => $query) {
            $inTransaction = $query['depth'] > $capture['ambient'];

            if ($lockIndex === null && $inTransaction && str_starts_with($query['sql'], 'select')
                && str_contains($query['sql'], 'for update') && str_contains($query['sql'], "`{$table}`")) {
                $lockIndex = $index;
            }

            if ($guardIndex === null && str_contains($query['sql'], '`roles`')
                && in_array('owner', $query['bindings'], true)) {
                $guardIndex = $index;
            }

            if ($writeIndex === null && $inTransaction
                && (str_starts_with($query['sql'], 'update') || str_starts_with($query['sql'], 'insert'))
                && (str_contains($query['sql'], "`{$table}`") || str_contains($query['sql'], '`stored_events`'))) {
                $writeIndex = $index;
            }
        }

        $this->assertNotNull($lockIndex, "Expected a select-for-update on `{$table}` inside the interactor's own transaction.");
        $this->assertNotNull($guardIndex, 'Expected the owner-guard roles read during the request.');
        $this->assertNotNull($writeIndex, 'Expected an in-transaction write to follow the locked read.');
        $this->assertGreaterThan($lockIndex, $guardIndex, 'The owner-guard must decide AFTER the row is locked.');
        $this->assertGreaterThan($lockIndex, $writeIndex, 'The write must follow the locked read.');
    }

    /**
     * The accept decided under the invitation row lock (OQ-1-162): exactly ONE
     * top-level transaction spanned the write, the FIRST in-transaction read of
     * `invitations` was the locked one (an unlocked pre-read inside the window
     * would re-arm the TOCTOU), and the event write followed it in the same
     * window. The pre-transaction fast-fail read is unlocked by design.
     *
     * @param  array{ambient: int, queries: array<int, array{sql: string, bindings: array<int, mixed>, depth: int}>, transactions: int}  $capture
     */
    protected function assertAcceptDecidedUnderInvitationLock(array $capture, string $invitationId): void
    {
        $lockIndex = null;
        $writeIndex = null;

        foreach ($capture['queries'] as $index => $query) {
            $inTransaction = $query['depth'] > $capture['ambient'];

            if ($lockIndex === null && $inTransaction && str_starts_with($query['sql'], 'select')
                && str_contains($query['sql'], 'from `invitations`')) {
                $this->assertStringContainsString('for update', $query['sql'], 'The FIRST in-transaction read of invitations must be the locked one.');
                $this->assertContains($invitationId, $query['bindings'], 'The locked read must target THIS invitation row.');
                $lockIndex = $index;
            }

            if ($writeIndex === null && $inTransaction && str_starts_with($query['sql'], 'insert')
                && str_contains($query['sql'], '`stored_events`')) {
                $writeIndex = $index;
            }
        }

        $this->assertNotNull($lockIndex, 'No locked in-transaction read of invitations was recorded.');
        $this->assertNotNull($writeIndex, 'No in-transaction event write was recorded.');
        $this->assertGreaterThan($lockIndex, $writeIndex, 'The event write must follow the locked read.');
        $this->assertSame(1, $capture['transactions'], 'Expected exactly ONE top-level transaction to span the accept.');
    }

    /**
     * The (company, user) pivot set was locked before the write (OQ-1-162): an
     * in-transaction `company_user` FOR UPDATE read ran after the invitation
     * lock (the fixed invitations → company_user order) and before the event
     * write — serializing the accept against the membership writers that lock
     * the same rows. NOT self-sufficient: depth alone cannot distinguish one
     * transaction from two sequential ones — pair with
     * assertAcceptDecidedUnderInvitationLock, whose transactions === 1 assert
     * closes that split.
     *
     * @param  array{ambient: int, queries: array<int, array{sql: string, bindings: array<int, mixed>, depth: int}>, transactions: int}  $capture
     */
    protected function assertPivotSetLockedBeforeWrite(array $capture, string $companyId, string $userId): void
    {
        $invitationLockIndex = null;
        $pivotLockIndex = null;
        $writeIndex = null;

        foreach ($capture['queries'] as $index => $query) {
            if ($query['depth'] <= $capture['ambient']) {
                continue;
            }

            if ($invitationLockIndex === null && str_starts_with($query['sql'], 'select')
                && str_contains($query['sql'], 'from `invitations`') && str_contains($query['sql'], 'for update')) {
                $invitationLockIndex = $index;
            }

            if ($pivotLockIndex === null && str_starts_with($query['sql'], 'select')
                && str_contains($query['sql'], 'from `company_user`') && str_contains($query['sql'], 'for update')
                && in_array($companyId, $query['bindings'], true) && in_array($userId, $query['bindings'], true)) {
                $pivotLockIndex = $index;
            }

            if ($writeIndex === null && str_starts_with($query['sql'], 'insert')
                && str_contains($query['sql'], '`stored_events`')) {
                $writeIndex = $index;
            }
        }

        $this->assertNotNull($invitationLockIndex, 'No locked in-transaction read of invitations was recorded.');
        $this->assertNotNull($pivotLockIndex, 'No locked in-transaction read of company_user was recorded.');
        $this->assertNotNull($writeIndex, 'No in-transaction event write was recorded.');
        $this->assertGreaterThan($invitationLockIndex, $pivotLockIndex, 'The pivot lock must follow the invitation lock (fixed lock order).');
        $this->assertGreaterThan($pivotLockIndex, $writeIndex, 'The write must follow the locked pivot read.');
    }

    /**
     * Register a projector that throws $failure when $eventClass is dispatched
     * (weight 1000 — AFTER the real projectors, so their writes happen and the
     * rollback is proven against them). Register AFTER the arrange: the handler
     * matches every stored event and filters by class in the body.
     */
    protected function induceProjectorFailureFor(string $eventClass, ?Throwable $failure = null): void
    {
        $projector = new class extends Projector
        {
            public string $targetEventClass = '';

            public ?Throwable $failure = null;

            public int $weight = 1000;

            public function onStoredEvent(ShouldBeStored $event): void
            {
                if ($event::class === $this->targetEventClass) {
                    throw $this->failure ?? new RuntimeException('Induced projector failure');
                }
            }
        };
        $projector->targetEventClass = $eventClass;
        $projector->failure = $failure;

        // The projectionist re-resolves handlers from the container by class
        // name on every call; without this instance binding the dispatch would
        // get a FRESH copy whose target class is '' and never throw.
        app()->instance($projector::class, $projector);
        app(Projectionist::class)->addProjector($projector);
    }

    /**
     * The membership still holds a live (non-soft-deleted) pivot — a sad path
     * removed nothing (the LD state-unchanged discipline). withTrashed so the
     * lookup survives regardless of the pivot's soft-delete state.
     */
    protected function assertMemberStillActive(string $membershipId): void
    {
        $membership = CompanyUser::withTrashed()->whereKey($membershipId)->sole();

        $this->assertNull($membership->deleted_at);
    }

    /**
     * An accept interactor whose FIRST look returns $snapshot verbatim — the
     * stale pre-transaction view a concurrent writer leaves behind. The
     * in-transaction locked re-read stays production code; pair with
     * assertLockedInvitationReadRecorded so the seam is proven engaged.
     */
    protected function staleSnapshotAccept(Invitation $snapshot): AcceptInvitationInteractor
    {
        $interactor = new class(app(SessionIssuer::class)) extends AcceptInvitationInteractor
        {
            public ?Invitation $staleSnapshot = null;

            protected function livePendingInvitation(string $plaintextToken): Invitation
            {
                return $this->staleSnapshot ?? parent::livePendingInvitation($plaintextToken);
            }
        };
        $interactor->staleSnapshot = $snapshot;

        return $interactor;
    }

    /**
     * The transaction's locked invitation re-read actually executed — the
     * positive control proving a stale-snapshot refusal came from the
     * production re-check, not from some earlier path.
     *
     * @param  array{ambient: int, queries: array<int, array{sql: string, bindings: array<int, mixed>, depth: int}>, transactions: int}  $capture
     */
    protected function assertLockedInvitationReadRecorded(array $capture, string $invitationId): void
    {
        $locked = null;

        foreach ($capture['queries'] as $query) {
            if ($query['depth'] > $capture['ambient'] && str_starts_with($query['sql'], 'select')
                && str_contains($query['sql'], 'from `invitations`') && str_contains($query['sql'], 'for update')
                && in_array($invitationId, $query['bindings'], true)) {
                $locked = $query;
                break;
            }
        }

        $this->assertNotNull($locked, 'No locked in-transaction invitations re-read was recorded — the seam did not engage the re-check.');
    }

    /**
     * The refused accept left the removal intact: the invitation stays
     * unconsumed, the member's original pivot remains soft-deleted, and no
     * second row appeared in the (company, user) unique slot.
     */
    protected function assertRefusalLeftMemberRemoved(Company $company, string $email, string $membershipId): void
    {
        $this->assertNull(Invitation::query()
            ->where('company_id', $company->getKey())
            ->where('email', $email)
            ->sole()
            ->accepted_at);
        $this->assertNotNull(CompanyUser::withTrashed()->whereKey($membershipId)->sole()->deleted_at);
        $this->assertSame(1, CompanyUser::withTrashed()
            ->where('company_id', $company->getKey())
            ->whereIn('user_id', User::query()->where('email', $email)->pluck('id'))
            ->count());
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
     * A member of $company holding the SEEDED $roleCode. Returns the user and
     * the membership pivot id (the aggregate identity, D5b).
     *
     * @return array{0: User, 1: string}
     */
    protected function aMembership(Company $company, string $roleCode): array
    {
        $user = User::factory()->create();
        $this->attachMember($company, $user, $this->seededRole($roleCode));

        return [$user, $this->membershipIdOf($company, $user)];
    }

    /**
     * The membership pivot id for ($company, $user) — the aggregate identity.
     */
    protected function membershipIdOf(Company $company, User $user): string
    {
        return CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->sole()
            ->getKey();
    }

    /**
     * A REAL accepted membership for ($company, $email): invited through the
     * endpoint as $actor (employee role) and accepted as a new user — so the
     * membership carries a genuine UserMembership event stream whose genesis
     * metadata tags company_id (unlike a direct attachMember pivot, which has
     * no stream). Returns [the joiner, the membership id]. Guard-clean (P).
     *
     * @return array{0: User, 1: string}
     */
    protected function anAcceptedMembership(Company $company, User $actor, string $email): array
    {
        $token = $this->aPendingInvitation($company, $actor, $email);
        $this->acceptInvitation($token, $this->validNewUserBody())->assertStatus(201);

        Auth::forgetGuards();

        $joiner = User::query()->where('email', $email)->sole();

        return [$joiner, $this->membershipIdOf($company, $joiner)];
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
     * The optional revocation reason revokeInvitationWithReason sends — tests set
     * it per scenario (hoisted with the revoke helpers from RevokeInvitationTest).
     */
    protected string $reason = '';

    /**
     * Revoke the invitation $invitationId as $actor acting in $company's context.
     */
    protected function revokeInvitation(User $actor, Company $company, string $invitationId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->deleteJson(
            "/api/v1/invitations/{$invitationId}",
            [],
            ['X-Company-Context' => $company->getKey()]
        );
    }

    /**
     * Revoke with the shared $this->reason riding as the optional request body.
     */
    protected function revokeInvitationWithReason(User $actor, Company $company, string $invitationId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->deleteJson(
            "/api/v1/invitations/{$invitationId}",
            ['reason' => $this->reason],
            ['X-Company-Context' => $company->getKey()]
        );
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
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', UserInvitationRevoked::class)
            ->where('event_properties->membershipId', $invitation->getKey())
            ->count());
    }

    /**
     * The invitation for ($company, $email) was NOT consumed: it remains
     * un-accepted and no membership pivot (live or trashed) exists for that
     * email's user in the company.
     */
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
