<?php

namespace Tests\Feature\Compliance\Dsar;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\CompanyUser;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarRequestCancelled;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

abstract class DsarTestCase extends TestCase
{
    protected ?string $idempotencyKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
        $this->travelTo('2026-10-01 09:00:00');
        config(['app.debug' => false]);
        $this->idempotencyKey = (string) Str::uuid();
    }

    /**
     * A fresh user made a live member of $company under a seeded role.
     */
    protected function aCompanyMember(Company $company, string $roleCode = 'employee'): User
    {
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole($roleCode)->getKey()]);

        return $user;
    }

    /**
     * The id of a user who is not a live member of $company: a member of
     * another company, a removed (pivot soft-deleted) member, or no user.
     */
    protected function aNonMemberSubject(string $kind, Company $company): string
    {
        return match ($kind) {
            'other-company-member' => $this->aCompanyMember(Company::factory()->create())->getKey(),
            'removed-member' => $this->aRemovedMember($company)->getKey(),
            'unknown-user' => (string) Str::uuid(),
            default => throw new InvalidArgumentException("Unknown non-member kind: {$kind}"),
        };
    }

    protected function aRemovedMember(Company $company): User
    {
        $user = $this->aCompanyMember($company);

        CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->update(['deleted_at' => now()]);

        return $user;
    }

    /**
     * A second request for $subject under a fresh idempotency key — made by the
     * subject themselves, or by an admin on their behalf.
     *
     * @return array{0: User, 1: array<string, string>}
     */
    protected function aRepeatRequestFor(User $subject, Company $company, string $requester): array
    {
        $this->idempotencyKey = (string) Str::uuid();

        if ($requester === 'admin') {
            return [$this->aCompanyMember($company, 'admin'), ['subject_user_id' => $subject->getKey()]];
        }

        return [$subject, []];
    }

    /**
     * A request recorded the event-sourced way — a real DsarRequestSubmitted
     * on its own stream — for $subject, made by $requester (the subject when
     * omitted).
     */
    protected function aSubmittedRequest(Company $company, User $subject, ?User $requester = null): DsarRequestRecord
    {
        $requester ??= $subject;
        $requestId = Str::uuid7()->toString();

        DsarRequest::retrieve($requestId)
            ->submit(SubmitDsarRequestCommand::from([
                'subject_user_id' => $subject->getKey(),
                'companyId' => $company->getKey(),
                'requestedByUserId' => $requester->getKey(),
            ]), $this->roleCodeOf($requester, $company->getKey()))
            ->persist();

        return DsarRequestRecord::query()->findOrFail($requestId);
    }

    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithViewAll(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutViewAll(): array
    {
        return [
            'employee' => ['employee'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'auditor' => ['auditor'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnknownRequestIds(): array
    {
        return [
            'random uuid' => [(string) Str::uuid()],
            'malformed text' => ['not-a-request-id'],
            'number' => ['12345'],
        ];
    }

    /**
     * An authenticated member submits a DSAR request in $company's context,
     * carrying the shared idempotency key when one is set.
     *
     * @param  array<string, mixed>  $body
     */
    protected function submitDsarRequest(User $actor, Company $company, array $body = []): TestResponse
    {
        Sanctum::actingAs($actor);

        $headers = ['X-Company-Context' => $company->getKey()];
        if ($this->idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $this->idempotencyKey;
        }

        return $this->postJson('/api/v1/dsar-requests', $body, $headers);
    }

    /**
     * A guest (no Sanctum session) submits a DSAR request.
     *
     * @param  array<string, mixed>  $body
     */
    protected function submitDsarRequestAsGuest(array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/dsar-requests', $body, ['Idempotency-Key' => $this->idempotencyKey]);
    }

    /**
     * An authenticated member lists the DSAR requests visible to them in
     * $company's context.
     *
     * @param  array<string, mixed>  $query
     */
    protected function listDsarRequests(User $actor, Company $company, array $query = []): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/dsar-requests'.$this->queryString($query), ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * $request's row in the list carries every resource field as recorded,
     * with $downloadAvailable stated by the test.
     */
    protected function assertListedRowShows(TestResponse $response, DsarRequestRecord $request, bool $downloadAvailable): void
    {
        $response->assertOk();
        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json('data');
        $this->assertSame($this->expectedRow($request, $downloadAvailable), collect($rows)->firstWhere('id', $request->getKey()));
    }

    /**
     * The detail answered with $request — every resource field as recorded,
     * with $downloadAvailable stated by the test.
     */
    protected function assertDsarRequestShown(TestResponse $response, DsarRequestRecord $request, bool $downloadAvailable): void
    {
        $response->assertOk();
        $this->assertSame($this->expectedRow($request, $downloadAvailable), $response->json('data'));
    }

    /**
     * Every resource field of $request as recorded, with $downloadAvailable
     * stated by the test.
     *
     * @return array<string, mixed>
     */
    private function expectedRow(DsarRequestRecord $request, bool $downloadAvailable): array
    {
        return [
            'id' => $request->getKey(),
            'status' => $request->status->value,
            'request_type' => 'access',
            'subject' => ['id' => $request->subject->getKey(), 'name' => $request->subject->name],
            'requested_by' => ['id' => $request->requestedBy->getKey(), 'name' => $request->requestedBy->name],
            'requested_at' => $request->requested_at->toISOString(),
            'sla_deadline' => $request->requested_at->addDays(30)->toISOString(),
            'completed_at' => $request->completed_at?->toISOString(),
            'download_available' => $downloadAvailable,
            'download_expires_at' => $request->download_expires_at?->toISOString(),
            'cancelled_at' => $request->cancelled_at?->toISOString(),
            'rejection_reason' => null,
        ];
    }

    /**
     * The list call with the query log on around it.
     *
     * @return array{TestResponse, int}
     */
    protected function listDsarRequestsCountingQueries(User $actor, Company $company): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->listDsarRequests($actor, $company);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$response, $queryCount];
    }

    /**
     * A full first page came back and it cost fewer queries than it has rows —
     * the relations are loaded once for the page, not once per row.
     */
    protected function assertListedWithFewerQueriesThanRows(TestResponse $response, int $queryCount): void
    {
        $response->assertOk();
        $this->assertCount(15, $response->json('data'));
        $this->assertLessThan(15, $queryCount);
    }

    /**
     * The list asked for with $body sent as the request body instead of the
     * query string.
     *
     * @param  array<string, mixed>  $body
     */
    protected function listDsarRequestsWithBody(User $actor, Company $company, array $body): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->json('GET', '/api/v1/dsar-requests', $body, ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * A guest (no Sanctum session) lists DSAR requests.
     *
     * @param  array<string, mixed>  $query
     */
    protected function listDsarRequestsAsGuest(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/dsar-requests'.$this->queryString($query));
    }

    /**
     * An authenticated member views one DSAR request in $company's context.
     */
    protected function viewDsarRequest(User $actor, Company $company, string $requestId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/dsar-requests/'.$requestId, ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * A guest (no Sanctum session) views one DSAR request.
     */
    protected function viewDsarRequestAsGuest(string $requestId): TestResponse
    {
        return $this->getJson('/api/v1/dsar-requests/'.$requestId);
    }

    /**
     * An authenticated member withdraws one DSAR request in $company's context.
     */
    protected function withdrawDsarRequest(User $actor, Company $company, string $requestId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->patchJson('/api/v1/dsar-requests/'.$requestId.'/cancel', [], ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * A guest (no Sanctum session) withdraws one DSAR request.
     */
    protected function withdrawDsarRequestAsGuest(string $requestId): TestResponse
    {
        return $this->patchJson('/api/v1/dsar-requests/'.$requestId.'/cancel');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function queryString(array $query): string
    {
        return $query === [] ? '' : '?'.http_build_query($query);
    }

    /**
     * The list answered with exactly $expectedIds, in that order, and a total
     * that counts only them.
     *
     * @param  list<string>  $expectedIds
     */
    protected function assertDsarRequestsListed(TestResponse $response, array $expectedIds): void
    {
        $response->assertOk();
        $this->assertSame($expectedIds, $response->json('data.*.id'));
        $this->assertSame(count($expectedIds), $response->json('meta.pagination.total'));
    }

    /**
     * One page of the list — exactly $expectedIds in order, under the exact
     * pagination meta.
     *
     * @param  list<string>  $expectedIds
     * @param  array<string, int>  $pagination
     */
    protected function assertDsarRequestPage(TestResponse $response, array $expectedIds, array $pagination): void
    {
        $response->assertOk();
        $this->assertSame($expectedIds, $response->json('data.*.id'));
        $this->assertSame($pagination, $response->json('meta.pagination'));
    }

    /**
     * The request was rejected as unauthenticated — 401 with the §S21
     * UNAUTHENTICATED envelope (module bases are independent — the
     * CompaniesTestCase mirror).
     */
    protected function assertUnauthenticated(TestResponse $response): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }

    /**
     * The input was refused as invalid on $invalidField — 422 VALIDATION_FAILED
     * whose field-level errors contain $expectedMessage (§2.2 Rule 10).
     */
    protected function assertValidationRejected(TestResponse $response, string $invalidField, string $expectedMessage): void
    {
        $response->assertUnprocessable();
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors([$invalidField => $expectedMessage]);
    }

    /**
     * The member's role lacks the required permission — 403 ROLE_INSUFFICIENT
     * naming the missing grant.
     */
    protected function assertRoleInsufficient(TestResponse $response, string $permission): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'ROLE_INSUFFICIENT');
        $response->assertJsonPath('required_permission', $permission);
    }

    /**
     * The requested resource does not exist — 404 with the §S21 envelope and no
     * existence leak.
     */
    protected function assertResourceNotFound(TestResponse $response): void
    {
        $response->assertNotFound();
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonPath('message', 'The requested resource was not found.');
    }

    /**
     * The request stayed hidden — the plain 404 envelope, nothing in the body
     * that names $requestId (the random correlation id aside).
     */
    protected function assertHiddenAsNotFound(TestResponse $response, string $requestId): void
    {
        $this->assertResourceNotFound($response);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
        $this->assertStringNotContainsString($requestId, (string) json_encode(Arr::except((array) $response->json(), 'correlation_id')));
    }

    /**
     * The submission was refused because an export is already being prepared
     * for the subject — 409 DSAR_IN_PROGRESS with the UC-1-070 message.
     */
    protected function assertRefusedAsInProgress(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::DsarInProgress);
        $response->assertJsonPath('message', 'A data export is already being prepared.');
    }

    /**
     * Exactly one request exists for $subject in $company — one submitted event,
     * one projected row and one audit entry.
     */
    protected function assertOneDsarRequestRecorded(Company $company, User $subject): void
    {
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', DsarRequestSubmitted::class)
            ->whereForCompany($company->getKey())
            ->count());
        $this->assertSame(1, DsarRequestRecord::query()
            ->where('company_id', $company->getKey())
            ->where('subject_user_id', $subject->getKey())
            ->count());
        $this->assertDatabaseCount('audit_trail', 1);
    }

    /**
     * The withdrawal was refused because the actor did not make the request —
     * 403 DSAR_REQUESTER_MISMATCH with the UC-1-102 message.
     */
    protected function assertRefusedAsNotTheRequester(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::DsarRequesterMismatch);
        $response->assertJsonPath('message', 'Only the original requester can withdraw this DSAR.');
    }

    /**
     * The withdrawal was refused because the request is no longer in progress —
     * 409 STATE_GUARD_VIOLATION with the UC-1-102 message.
     */
    protected function assertRefusedAsNoLongerInProgress(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::StateGuardViolation);
        $response->assertJsonPath('message', 'DSAR is no longer in progress.');
    }

    /**
     * $request was withdrawn exactly once — one DsarRequestCancelled on its
     * stream and one withdrawal audited — and stays cancelled.
     */
    protected function assertWithdrawnOnce(DsarRequestRecord $request): void
    {
        $this->assertSame(DsarRequestStatus::Cancelled, $request->fresh()?->status);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestCancelled::class)
            ->count());
        $this->assertSame(1, DB::table('audit_trail')->where('subject_id', $request->getKey())->where('action', 'dsar_withdrawn')->count());
    }

    /**
     * The withdrawal was recorded the event-sourced way: the request answered
     * as cancelled, its row stamped with when and by whom, and one
     * DsarRequestCancelled naming the actor on its stream.
     */
    protected function assertWithdrawn(TestResponse $response, DsarRequestRecord $request, User $actor): void
    {
        $withdrawn = $request->fresh() ?? $request;
        $this->assertDsarRequestShown($response, $withdrawn, false);
        $this->assertSame(
            [DsarRequestStatus::Cancelled, now()->toISOString(), $actor->getKey()],
            [$withdrawn->status, $withdrawn->cancelled_at?->toISOString(), $withdrawn->cancelled_by],
        );
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', DsarRequestCancelled::class)
            ->where('aggregate_uuid', $request->getKey())
            ->whereForCompany($request->company_id)
            ->where('event_properties->cancelledByUserId', $actor->getKey())
            ->count());
    }

    /**
     * Exactly one audit entry records the withdrawal — "DSAR withdrawn — for:
     * <subject>, by: <actor>" — linked to its stored event and stamped with the
     * actor's role.
     */
    protected function assertWithdrawalAudited(DsarRequestRecord $request, User $subject, User $actor): void
    {
        $storedEventId = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestCancelled::class)
            ->sole()
            ->id;

        $this->assertSame(1, DB::table('audit_trail')->where('subject_id', $request->getKey())->where('action', 'dsar_withdrawn')->count());
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $request->company_id,
            'stored_event_id' => $storedEventId,
            'event_class' => 'DsarRequestCancelled',
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'action' => 'dsar_withdrawn',
            'description' => 'DSAR withdrawn — for: '.$subject->name.', by: '.$actor->name,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $this->roleCodeOf($actor, $request->company_id),
            'actor_label' => $actor->name,
        ]);
    }

    /**
     * The submission was accepted and is the subject's one open request in
     * $company — any earlier requests there are closed.
     */
    protected function assertAcceptedAsTheOnlyOpenRequest(TestResponse $response, Company $company, User $subject): void
    {
        $response->assertCreated();
        $this->assertSame(1, DsarRequestRecord::query()
            ->where('company_id', $company->getKey())
            ->where('subject_user_id', $subject->getKey())
            ->where('status', DsarRequestStatus::InProgress)
            ->count());
    }

    /**
     * $replay is the remembered answer to $first — same status and body,
     * flagged as replayed.
     */
    protected function assertReplayedOf(TestResponse $replay, TestResponse $first): void
    {
        $replay->assertCreated();
        $replay->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->getContent(), $replay->getContent());
    }

    /**
     * The submission was recorded the event-sourced way: a 201 request
     * resource in progress with its 30-day deadline, one DsarRequestSubmitted
     * event naming subject and requester, and the projected row carrying the
     * requester's role at submission.
     */
    protected function assertDsarRequestRecorded(TestResponse $response, User $subject, User $requester): void
    {
        $response->assertCreated();
        $record = $this->dsarRequestIn($response);
        $response->assertJsonPath('data', [
            'id' => $record->getKey(),
            'status' => 'in_progress',
            'request_type' => 'access',
            'subject' => ['id' => $subject->getKey(), 'name' => $subject->name],
            'requested_by' => ['id' => $requester->getKey(), 'name' => $requester->name],
            'requested_at' => now()->toISOString(),
            'sla_deadline' => now()->addDays(30)->toISOString(),
            'completed_at' => null,
            'download_available' => false,
            'download_expires_at' => null,
            'cancelled_at' => null,
            'rejection_reason' => null,
        ]);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', DsarRequestSubmitted::class)
            ->where('aggregate_uuid', $record->getKey())
            ->where('event_properties->companyId', $record->company_id)
            ->where('event_properties->subjectUserId', $subject->getKey())
            ->where('event_properties->requestedByUserId', $requester->getKey())
            ->where('event_properties->requestType', 'access')
            ->count());
        $this->assertSame($this->roleCodeOf($requester, $record->company_id), $record->requested_by_role);
    }

    /**
     * Exactly one audit entry records the request — "DSAR requested — for:
     * <subject>, by: <requester>" — linked to its stored event and stamped
     * with the requester's role.
     */
    protected function assertDsarRequestAudited(TestResponse $response, User $subject, User $requester): void
    {
        $record = $this->dsarRequestIn($response);
        $storedEventId = LesilyStoredEvent::query()->where('aggregate_uuid', $record->getKey())->sole()->id;

        $this->assertDatabaseCount('audit_trail', 1);
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $record->company_id,
            'stored_event_id' => $storedEventId,
            'event_class' => 'DsarRequestSubmitted',
            'subject_type' => 'dsar_request',
            'subject_id' => $record->getKey(),
            'action' => 'dsar_requested',
            'description' => 'DSAR requested — for: '.$subject->name.', by: '.$requester->name,
            'actor_user_id' => $requester->getKey(),
            'actor_role_code' => $this->roleCodeOf($requester, $record->company_id),
            'actor_label' => $requester->name,
        ]);
    }

    private function dsarRequestIn(TestResponse $response): DsarRequestRecord
    {
        $requestId = $response->json('data.id');
        $this->assertIsString($requestId);

        return DsarRequestRecord::query()->findOrFail($requestId);
    }

    private function roleCodeOf(User $member, string $companyId): string
    {
        $roleId = CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $member->getKey())
            ->value('role_id');

        return Role::query()->whereKey($roleId)->value('code');
    }

    /**
     * A refused withdrawal left $request as it was — still in progress, its
     * stream holding only the submission, no withdrawal audited.
     */
    protected function assertStillInProgress(DsarRequestRecord $request): void
    {
        $this->assertSame(DsarRequestStatus::InProgress, $request->fresh()?->status);
        $this->assertSame(1, LesilyStoredEvent::query()->where('aggregate_uuid', $request->getKey())->count());
        $this->assertDatabaseMissing('audit_trail', ['subject_id' => $request->getKey(), 'action' => 'dsar_withdrawn']);
    }

    /**
     * A refused submission left no trace in $company — no event stored and no
     * request projected, no audit entry.
     */
    protected function assertNoDsarRequestRecorded(Company $company): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->whereForCompany($company->getKey())->count());
        $this->assertSame(0, DsarRequestRecord::query()->where('company_id', $company->getKey())->count());
        $this->assertDatabaseMissing('audit_trail', ['company_id' => $company->getKey()]);
    }
}
