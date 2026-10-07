<?php

namespace Tests\Feature\Compliance\Dsar;

use Throwable;
use ZipArchive;
use Tests\TestCase;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Consent\Aggregates\Consent;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Queue;
use App\Companies\Entities\CompanyUser;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Testing\Constraints\SeeInOrder;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Companies\Memberships\Entities\Invitation;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarRequestRejected;
use App\Compliance\Dsar\Events\DsarRequestCancelled;
use App\Compliance\Dsar\Events\DsarRequestFulfilled;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;
use App\Compliance\Dsar\IO\Export\DsarExportArchive;
use App\Compliance\Dsar\UseCases\Reports\DsarReport;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;
use App\Consent\UseCases\Commands\RecordConsentCommand;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Compliance\Dsar\UseCases\Audit\DsarOutcomeAudit;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Compliance\Dsar\UseCases\Jobs\BuildDsarExportJob;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Compliance\Dsar\UseCases\Sections\DsarSectionProvider;
use App\Foundation\Notifications\Notifications\SystemNotification;
use App\Compliance\Dsar\UseCases\Commands\CancelDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\FulfilDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\RejectDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;
use App\Companies\UseCases\Commands\TransferCompanyOwnershipCommand;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Dsar\Exceptions\DsarReportRenderingFailedException;
use App\Compliance\Dsar\UseCases\Renderers\DsarReportRendererInterface;
use App\Companies\Memberships\UseCases\Commands\CreateInvitationCommand;
use App\Companies\Memberships\UseCases\Commands\ResendInvitationCommand;
use App\Companies\Memberships\UseCases\Commands\RevokeInvitationCommand;
use App\Compliance\Dsar\UseCases\Interactors\RejectDsarRequestInteractor;
use App\Companies\Memberships\UseCases\Commands\ChangeMembershipRoleCommand;

abstract class DsarTestCase extends TestCase
{
    use InteractsWithQueuedReactors;

    public const STAND_IN_REPORT = '%PDF stand-in';

    protected ?string $idempotencyKey;

    protected RecordingDsarReportRenderer $reportRenderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbacCatalog();
        $this->travelTo('2026-10-01 09:00:00');
        config(['app.debug' => false]);
        $this->idempotencyKey = (string) Str::uuid();
        Queue::fake();
        Storage::fake('dsar');
        $this->reportRenderer = new RecordingDsarReportRenderer;
        $this->app->instance(DsarReportRendererInterface::class, $this->reportRenderer);
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
     * @return array<string, array{string, DsarRequestStatus}>
     */
    public static function provideClosedRequests(): array
    {
        return [
            'withdrawn' => ['withdrawn', DsarRequestStatus::Cancelled],
            'fulfilled' => ['fulfilled', DsarRequestStatus::Fulfilled],
            'rejected' => ['rejected', DsarRequestStatus::Rejected],
        ];
    }

    /**
     * @return array<string, array{string, DsarRequestStatus}>
     */
    public static function provideUnfulfilledClosedRequests(): array
    {
        return [
            'withdrawn' => ['withdrawn', DsarRequestStatus::Cancelled],
            'rejected' => ['rejected', DsarRequestStatus::Rejected],
        ];
    }

    /**
     * $subject's own request, closed the event-sourced way $closedBy names —
     * withdrawn by them, fulfilled by its export, or rejected after its export
     * failed.
     */
    protected function aClosedRequest(Company $company, User $subject, string $closedBy): DsarRequestRecord
    {
        $request = $this->aSubmittedRequest($company, $subject);
        $aggregate = DsarRequest::retrieve($request->getKey());

        match ($closedBy) {
            'withdrawn' => $aggregate->cancel(CancelDsarRequestCommand::from([
                'requestId' => $request->getKey(),
                'companyId' => $company->getKey(),
                'cancelledByUserId' => $subject->getKey(),
            ])),
            'fulfilled' => $aggregate->fulfil(FulfilDsarRequestCommand::from([
                'requestId' => $request->getKey(),
                'filePath' => $this->exportPathOf($request),
            ])),
            'rejected' => $aggregate->reject(RejectDsarRequestCommand::from([
                'requestId' => $request->getKey(),
                'reason' => 'The export could not be built. Please submit a new request.',
            ])),
            default => throw new InvalidArgumentException("Unknown closure: {$closedBy}"),
        };
        $aggregate->persist();

        return $request;
    }

    /**
     * $request is still closed as $status: its stream holds the submission
     * and the one event that closed it — nothing recorded since.
     */
    protected function assertStillClosedAs(DsarRequestRecord $request, DsarRequestStatus $status): void
    {
        $this->assertSame($status, $request->fresh()?->status);
        $this->assertSame(2, LesilyStoredEvent::query()->where('aggregate_uuid', $request->getKey())->count());
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
     * A refused or rolled-back change left $request as it was — still in
     * progress, its stream holding only the submission, no withdrawal audited.
     */
    protected function assertStillInProgress(DsarRequestRecord $request): void
    {
        $this->assertSame(DsarRequestStatus::InProgress, $request->fresh()?->status);
        $this->assertSame(1, LesilyStoredEvent::query()->where('aggregate_uuid', $request->getKey())->count());
        $this->assertSame(0, DB::table('audit_trail')->where('subject_id', $request->getKey())->count());
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

    /**
     * The export collects exactly $sections — the container hands the job
     * this list instead of the registered one.
     */
    protected function withSections(DsarSectionProvider ...$sections): void
    {
        $this->app->instance(DsarSections::class, new DsarSections(array_values($sections)));
    }

    /**
     * A section that withdraws $request, as its requester, while the export
     * is being collected — the race a worker can lose.
     */
    protected function aSectionThatWithdraws(DsarRequestRecord $request): DsarSectionProvider
    {
        return new class($request) implements DsarSectionProvider
        {
            public function __construct(private readonly DsarRequestRecord $request) {}

            public function key(): string
            {
                return 'withdraws';
            }

            public function collect(string $subjectUserId, string $companyId): array
            {
                DsarRequest::retrieve($this->request->getKey())
                    ->cancel(CancelDsarRequestCommand::from([
                        'requestId' => $this->request->getKey(),
                        'companyId' => $companyId,
                        'cancelledByUserId' => $this->request->requested_by,
                    ]))
                    ->persist();

                return [];
            }
        };
    }

    /**
     * Nothing was fulfilled for $request: no DsarRequestFulfilled on its
     * stream, no fulfilment audited, no export file left, and its status is
     * still $status.
     */
    protected function assertNothingFulfilled(DsarRequestRecord $request, DsarRequestStatus $status): void
    {
        $this->assertSame($status, $request->fresh()?->status);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestFulfilled::class)
            ->count());
        $this->assertDatabaseMissing('audit_trail', ['subject_id' => $request->getKey(), 'action' => 'dsar_fulfilled']);
        Storage::disk('dsar')->assertMissing('dsar/'.$request->company_id.'/'.$request->getKey().'.zip');
    }

    /**
     * The half-written ZIP a failed try leaves on the dsar disk for $request.
     */
    protected function aPartialExportFor(DsarRequestRecord $request): void
    {
        Storage::disk('dsar')->put($this->exportPathOf($request), 'partial');
    }

    /**
     * The worker gave up on $request's export: failed() runs once, after the
     * last try, with the error that ended it.
     */
    protected function failTheExportFor(DsarRequestRecord $request, Throwable $cause): void
    {
        (new BuildDsarExportJob($request->getKey(), $request->company_id, $request->subject_user_id))->failed($cause);
    }

    /**
     * $request was rejected the event-sourced way: one DsarRequestRejected on
     * its stream, the row rejected now with the fixed reason — never the error
     * — and no export file left.
     */
    protected function assertRejected(DsarRequestRecord $request): void
    {
        $rejected = $request->fresh() ?? $request;

        $this->assertSame(
            [DsarRequestStatus::Rejected, now()->toISOString(), 'The export could not be built. Please submit a new request.'],
            [$rejected->status, $rejected->rejected_at?->toISOString(), $rejected->rejection_reason],
        );
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', DsarRequestRejected::class)
            ->where('aggregate_uuid', $request->getKey())
            ->whereForCompany($request->company_id)
            ->count());
        Storage::disk('dsar')->assertMissing($this->exportPathOf($request));
    }

    /**
     * Exactly one audit entry records the rejection — "DSAR rejected — for:
     * <subject>" — linked to its stored event, with no actor (ERD-1-11).
     */
    protected function assertRejectionAudited(DsarRequestRecord $request, User $subject): void
    {
        $storedEventId = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestRejected::class)
            ->sole()
            ->id;

        $this->assertSame(1, DB::table('audit_trail')->where('subject_id', $request->getKey())->where('action', 'dsar_rejected')->count());
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $request->company_id,
            'stored_event_id' => $storedEventId,
            'event_class' => 'DsarRequestRejected',
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'action' => 'dsar_rejected',
            'description' => 'DSAR rejected — for: '.$subject->name,
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
        ]);
    }

    /**
     * Nothing was rejected for $request: no DsarRequestRejected on its stream,
     * no rejection audited, no export file left, and its status is still
     * $status.
     */
    protected function assertNothingRejected(DsarRequestRecord $request, DsarRequestStatus $status): void
    {
        $this->assertSame($status, $request->fresh()?->status);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestRejected::class)
            ->count());
        $this->assertDatabaseMissing('audit_trail', ['subject_id' => $request->getKey(), 'action' => 'dsar_rejected']);
        Storage::disk('dsar')->assertMissing($this->exportPathOf($request));
    }

    /**
     * A failed change left $request still in progress with nothing recorded
     * since, and no partial export file at its path.
     */
    protected function assertStillInProgressWithoutExportFile(DsarRequestRecord $request): void
    {
        $this->assertStillInProgress($request);
        Storage::disk('dsar')->assertMissing($this->exportPathOf($request));
    }

    /**
     * A sibling run of $request's export fulfils it in the instant before this
     * run's reject lands — the reject then finds the request already closed.
     */
    protected function aSiblingRunFulfilsBeforeTheReject(DsarRequestRecord $request): void
    {
        $this->app->instance(RejectDsarRequestInteractor::class, new class(app(DsarOutcomeAudit::class), $request) extends RejectDsarRequestInteractor
        {
            public function __construct(DsarOutcomeAudit $audit, private readonly DsarRequestRecord $request)
            {
                parent::__construct($audit);
            }

            public function execute(RejectDsarRequestCommand $command): void
            {
                DsarRequest::retrieve($this->request->getKey())
                    ->fulfil(FulfilDsarRequestCommand::from([
                        'requestId' => $this->request->getKey(),
                        'filePath' => 'dsar/'.$this->request->company_id.'/'.$this->request->getKey().'.zip',
                    ]))
                    ->persist();

                parent::execute($command);
            }
        });
    }

    /**
     * $request is still closed as $status with nothing recorded since, and no
     * export file is left at its path — no personal data outlives it (N27).
     */
    protected function assertStillClosedWithoutExportFile(DsarRequestRecord $request, DsarRequestStatus $status): void
    {
        $this->assertStillClosedAs($request, $status);
        Storage::disk('dsar')->assertMissing($this->exportPathOf($request));
    }

    /**
     * $request is still fulfilled with nothing recorded since, and the export
     * planted at its path is still there, unchanged.
     */
    protected function assertStillFulfilledWithExportKept(DsarRequestRecord $request): void
    {
        $this->assertStillClosedAs($request, DsarRequestStatus::Fulfilled);
        $this->assertSame('partial', Storage::disk('dsar')->get($this->exportPathOf($request)));
    }

    /**
     * The renderer the application registers for the report — the stand-in
     * bound for the export tests set aside.
     */
    protected function theRegisteredReportRenderer(): DsarReportRendererInterface
    {
        $this->app->forgetInstance(DsarReportRendererInterface::class);

        return $this->app->make(DsarReportRendererInterface::class);
    }

    /**
     * The report cannot be rendered — the renderer produced no complete PDF.
     */
    protected function aReportRendererThatFails(): void
    {
        $this->app->instance(DsarReportRendererInterface::class, new class implements DsarReportRendererInterface
        {
            public function render(DsarReport $report): string
            {
                throw new DsarReportRenderingFailedException('induced rendering failure');
            }
        });
    }

    /**
     * A section under $key whose collection returns exactly $data.
     *
     * @param  array<int|string, mixed>  $data
     */
    protected function aSectionHolding(string $key, array $data): DsarSectionProvider
    {
        return new readonly class($key, $data) implements DsarSectionProvider
        {
            /**
             * @param  array<int|string, mixed>  $data
             */
            public function __construct(private string $key, private array $data) {}

            public function key(): string
            {
                return $this->key;
            }

            public function collect(string $subjectUserId, string $companyId): array
            {
                return $this->data;
            }
        };
    }

    /**
     * A section whose collection fails — a database or storage error mid-export.
     */
    protected function aSectionThatFails(): DsarSectionProvider
    {
        return new class implements DsarSectionProvider
        {
            public function key(): string
            {
                return 'fails';
            }

            public function collect(string $subjectUserId, string $companyId): array
            {
                throw new RuntimeException('induced section failure');
            }
        };
    }

    /**
     * $job gives the worker three tries, waiting one minute and then five
     * before the last, on the default queue (ruling N24).
     */
    protected function assertTriesThreeTimesWithBackoff(BuildDsarExportJob $job): void
    {
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff);
        $this->assertNull($job->queue);
    }

    /**
     * $user's consent to the terms and the privacy policy in $company,
     * recorded the event-sourced way from one IP.
     */
    protected function aConsentRecordedBy(User $user, Company $company): void
    {
        Consent::retrieve(Str::uuid7()->toString())
            ->record(RecordConsentCommand::from([
                'userId' => $user->getKey(),
                'companyId' => $company->getKey(),
                'policyVersions' => ['terms' => '2026-03', 'privacy' => '2026-04'],
                'ip' => '203.0.113.7',
            ]))
            ->persist();
    }

    /**
     * A sign-in of $user from $deviceName — a Sanctum token expiring at
     * $expiresAt, not used yet.
     */
    protected function aSignInFor(User $user, string $deviceName, CarbonInterface $expiresAt): PersonalAccessToken
    {
        return $user->createToken($deviceName, ['*'], $expiresAt)->accessToken;
    }

    /**
     * A $type notice sent to $recipient about $company — or about their whole
     * account when $company is null — as the production writers send it.
     */
    protected function aNotificationFor(User $recipient, NotificationType $type, ?Company $company): void
    {
        $recipient->notify(new SystemNotification(
            $type,
            $company === null ? $recipient->notificationCompanyName() : $company->name,
            $company?->getKey(),
        ));
    }

    /**
     * An invitation of $user's email to $company as an employee, recorded the
     * event-sourced way by a fresh inviter. Returns the invitation id.
     */
    protected function anInvitationTo(User $user, Company $company): string
    {
        return $this->anInvitationToEmail($user->email, $company);
    }

    /**
     * An invitation of $email, as typed, to $company as an employee.
     * Returns the invitation id.
     */
    protected function anInvitationToEmail(string $email, Company $company): string
    {
        $invitationId = Str::uuid7()->toString();

        UserMembership::retrieve($invitationId)
            ->invite(CreateInvitationCommand::from([
                'companyId' => $company->getKey(),
                'email' => $email,
                'roleId' => $this->seededRole('employee')->getKey(),
                'invitedByUserId' => User::factory()->create()->getKey(),
                'plaintextToken' => Str::random(40),
            ]))
            ->persist();

        return $invitationId;
    }

    /**
     * The invitation $invitationId goes out again on its own stream, now for
     * the role $roleCode.
     */
    protected function inviteAgain(string $invitationId, string $roleCode): void
    {
        $invitation = Invitation::query()->findOrFail($invitationId);

        UserMembership::retrieve($invitationId)
            ->invite(CreateInvitationCommand::from([
                'companyId' => $invitation->company_id,
                'email' => $invitation->email,
                'roleId' => $this->seededRole($roleCode)->getKey(),
                'invitedByUserId' => User::factory()->create()->getKey(),
                'plaintextToken' => Str::random(40),
            ]))
            ->persist();
    }

    protected function expireTheInvitation(string $invitationId): void
    {
        UserMembership::retrieve($invitationId)->expireIfPending(now())->persist();
    }

    protected function resendTheInvitation(string $invitationId): void
    {
        UserMembership::retrieve($invitationId)
            ->resendInvitation(ResendInvitationCommand::from([
                'plaintextToken' => Str::random(40),
                'resentBy' => User::factory()->create()->getKey(),
            ]))
            ->persist();
    }

    protected function revokeTheInvitation(string $invitationId): void
    {
        UserMembership::retrieve($invitationId)
            ->revokeInvitation(RevokeInvitationCommand::from([
                'revokedBy' => User::factory()->create()->getKey(),
                'reason' => 'Sent to the wrong team',
            ]))
            ->persist();
    }

    /**
     * $from hands $company to $to through the self-service transfer, recorded
     * the event-sourced way.
     */
    protected function anOwnershipTransfer(Company $company, User $from, User $to): void
    {
        CompanyAggregate::retrieve($company->getKey())
            ->transferOwnership(TransferCompanyOwnershipCommand::from([
                'newOwnerUserId' => $to->getKey(),
                'transferredBy' => $from->getKey(),
                'source' => TransferCompanyOwnershipCommand::SOURCE_SELF_SERVICE,
            ]), $from->getKey())
            ->persist();
    }

    /**
     * The employee who joined through $membershipId becomes a $roleCode.
     */
    protected function changeTheRole(string $membershipId, string $roleCode): void
    {
        UserMembership::retrieve($membershipId)
            ->changeRole(
                ChangeMembershipRoleCommand::from([
                    'roleCode' => $roleCode,
                    'changedBy' => User::factory()->create()->getKey(),
                ]),
                $this->seededRole('employee')->getKey(),
                $this->seededRole($roleCode)->getKey(),
            )
            ->persist();
    }

    /**
     * Support hands $company from $from to $to (the admin-assisted path),
     * recorded the event-sourced way.
     */
    protected function aSupportAssistedTransfer(Company $company, User $from, User $to): void
    {
        CompanyAggregate::retrieve($company->getKey())
            ->transferOwnership(TransferCompanyOwnershipCommand::from([
                'newOwnerUserId' => $to->getKey(),
                'transferredBy' => User::factory()->create()->getKey(),
                'source' => TransferCompanyOwnershipCommand::SOURCE_ADMIN,
            ]), $from->getKey())
            ->persist();
    }

    /**
     * $user accepts the invitation $invitationId as an employee.
     */
    protected function acceptTheInvitation(string $invitationId, User $user): void
    {
        UserMembership::retrieve($invitationId)
            ->acceptInvitation($user->getKey(), $this->seededRole('employee')->getKey())
            ->persist();
    }

    /**
     * The sections $request's export holds — data.json decoded from its ZIP
     * on the dsar disk.
     *
     * @return array<string, mixed>
     */
    protected function exportedData(DsarRequestRecord $request): array
    {
        return json_decode($this->exportedJsonOf($request), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * data.json exactly as $request's export ZIP holds it.
     */
    protected function exportedJsonOf(DsarRequestRecord $request): string
    {
        $zip = $this->exportZipOf($request);
        $json = (string) $zip->getFromName('data.json');
        $zip->close();

        return $json;
    }

    /**
     * $request's export ZIP holds exactly data.json and report.pdf, the
     * report being the bytes the renderer produced.
     */
    protected function assertExportHoldsDataAndReport(DsarRequestRecord $request): void
    {
        $zip = $this->exportZipOf($request);
        $entries = array_map(fn (int $index): string => (string) $zip->getNameIndex($index), range(0, $zip->numFiles - 1));
        $report = $zip->getFromName('report.pdf');
        $zip->close();

        $this->assertSame(['data.json', 'report.pdf'], $entries);
        $this->assertSame(self::STAND_IN_REPORT, $report);
    }

    /**
     * The report opens with the Lesily cover — whose data, which company,
     * which request, when — and then the guide to its two parts, in order.
     */
    protected function assertReportCover(DsarRequestRecord $request, User $subject, Company $company): void
    {
        $this->assertReportShowsInOrder([
            'lesily',
            'Your personal data',
            $subject->name,
            $subject->email,
            $company->name,
            $request->getKey(),
            'Requested',
            '1 Oct 2026, 09:00 UTC',
            'Generated',
            '1 Oct 2026, 09:00 UTC',
            'This export has two parts',
        ]);
    }

    /**
     * Every field of every section in $exported — nested ones included — has
     * a label in words, and the report shows no untranslated label key.
     *
     * @param  array<string, array<int|string, mixed>>  $exported
     */
    protected function assertEveryFieldLabelled(array $exported): void
    {
        $fields = [];
        foreach ($exported as $section => $data) {
            foreach ($this->fieldsIn($data) as $field) {
                $fields[] = "dsar.fields.{$section}.{$field}";
            }
        }
        $this->assertNotEmpty($fields);

        foreach (array_unique($fields) as $field) {
            $this->assertTrue(Lang::has($field), "No label for {$field}");
        }
        $this->assertStringNotContainsString('dsar.', $this->reportRenderer->html);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int, string>
     */
    protected function fieldsIn(array $data): array
    {
        $fields = array_is_list($data) ? [] : array_keys($data);
        foreach (array_filter($data, is_array(...)) as $nested) {
            $fields = [...$fields, ...$this->fieldsIn($nested)];
        }

        return $fields;
    }

    /**
     * The report holds a table cell reading exactly $text.
     */
    protected function assertReportCell(string $text): void
    {
        $this->assertStringContainsString('<td>'.e($text).'</td>', $this->reportRenderer->html);
    }

    /**
     * @param  array<int, string>  $texts
     */
    protected function assertReportShowsInOrder(array $texts): void
    {
        $this->assertThat(array_map(e(...), $texts), new SeeInOrder($this->reportRenderer->html));
    }

    protected function exportZipOf(DsarRequestRecord $request): ZipArchive
    {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('dsar')->path($this->exportPathOf($request)));

        return $zip;
    }

    /**
     * The archive builds its ZIP in a fresh, empty directory of its own —
     * returned so a test can see what the build leaves there.
     */
    protected function anArchiveWorkingInAnEmptyDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dsar-work-'.Str::uuid7()->toString();
        mkdir($directory);
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($directory));
        $this->app->instance(DsarExportArchive::class, new DsarExportArchive(new Filesystem, $directory));

        return $directory;
    }

    /**
     * $request's ZIP is on the dsar disk and the archive's working directory
     * holds nothing — no temporary copy of the person's data survives.
     */
    protected function assertExportBuiltWithoutLeftovers(DsarRequestRecord $request, string $workDirectory): void
    {
        Storage::disk('dsar')->assertExists($this->exportPathOf($request));
        $this->assertSame([], (new Filesystem)->files($workDirectory));
    }

    /**
     * Every section of $exported belongs to $company alone: $member's
     * membership of it, the consent they gave in it, the audit entries they
     * acted in there, and — of its invitations, transfers, cases and notices —
     * only the pending invitation, the transfer that made them owner, the open
     * case naming them and the notice about $company.
     *
     * @param  array<string, mixed>  $exported
     */
    protected function assertExportScopedTo(array $exported, Company $company, User $member): void
    {
        $this->assertSame(
            [$company->getKey(), $this->roleCodeOf($member, $company->getKey())],
            [$exported['membership']['company']['id'], $exported['membership']['role']],
        );
        $this->assertCount(2, $exported['consent_records']);
        $this->assertSame(
            AuditEntry::query()->where('company_id', $company->getKey())->where('actor_user_id', $member->getKey())->pluck('subject_id')->all(),
            array_column($exported['audit_trail'], 'subject_id'),
        );
        $this->assertSame(
            [[1], ['new_owner'], ['open'], [$company->name]],
            [
                array_map(fn (array $invitation): int => count($invitation['timeline']), $exported['invitations']),
                array_column($exported['ownership_transfers']['transfers'], 'role'),
                array_column($exported['ownership_transfers']['cases'], 'status'),
                array_column($exported['notifications']['notifications'], 'company_name'),
            ],
        );
    }

    /**
     * None of $user's secrets — password hash, remember token, two-factor
     * secret, recovery codes — nor any of $otherSecrets appears anywhere in
     * $exported.
     *
     * @param  array<string, mixed>  $exported
     * @param  array<int, string|null>  $otherSecrets
     */
    protected function assertNoSecretOf(User $user, array $exported, array $otherSecrets = []): void
    {
        $this->assertNoSecretIn(json_encode($exported, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $user, $otherSecrets);
    }

    /**
     * None of $user's secrets nor any of $otherSecrets appears anywhere in
     * the report.
     *
     * @param  array<int, string|null>  $otherSecrets
     */
    protected function assertReportHoldsNoSecretOf(User $user, array $otherSecrets): void
    {
        $this->assertNoSecretIn($this->reportRenderer->html, $user, $otherSecrets);
    }

    /**
     * @param  array<int, string|null>  $otherSecrets
     */
    protected function assertNoSecretIn(string $text, User $user, array $otherSecrets): void
    {
        $secrets = [
            $user->password,
            $user->remember_token,
            $user->two_factor_secret,
            $user->getRawOriginal('two_factor_secret'),
            ...($user->two_factor_recovery_codes ?? []),
            ...$otherSecrets,
        ];

        $this->assertSame($secrets, array_filter($secrets));
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString((string) $secret, $text);
        }
    }

    /**
     * The request a successful submission answered with.
     */
    protected function requestFrom(TestResponse $response): DsarRequestRecord
    {
        return DsarRequestRecord::query()->findOrFail((string) $response->json('data.id'));
    }

    protected function exportPathOf(DsarRequestRecord $request): string
    {
        return 'dsar/'.$request->company_id.'/'.$request->getKey().'.zip';
    }

    /**
     * Runs $request's export job the way a worker would — handle() with its
     * dependencies resolved from the container.
     */
    protected function runTheExportFor(DsarRequestRecord $request): void
    {
        app()->call([new BuildDsarExportJob($request->getKey(), $request->company_id, $request->subject_user_id), 'handle']);
    }

    /**
     * $request was fulfilled the event-sourced way: one DsarRequestFulfilled
     * naming its file, the row fulfilled now with a download window of seven
     * days, and the export ZIP on the private dsar disk.
     */
    protected function assertFulfilled(DsarRequestRecord $request): void
    {
        $filePath = 'dsar/'.$request->company_id.'/'.$request->getKey().'.zip';
        $fulfilled = $request->fresh() ?? $request;

        $this->assertSame(
            [DsarRequestStatus::Fulfilled, now()->toISOString(), $filePath, now()->addDays(7)->toISOString()],
            [$fulfilled->status, $fulfilled->completed_at?->toISOString(), $fulfilled->file_path, $fulfilled->download_expires_at?->toISOString()],
        );
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', DsarRequestFulfilled::class)
            ->where('aggregate_uuid', $request->getKey())
            ->whereForCompany($request->company_id)
            ->where('event_properties->filePath', $filePath)
            ->count());
        Storage::disk('dsar')->assertExists($filePath);
    }

    /**
     * Exactly one audit entry records the fulfilment — "DSAR fulfilled — for:
     * <subject>" — linked to its stored event, with no actor: the export job
     * is a system process (ERD-1-11).
     */
    protected function assertFulfilmentAudited(DsarRequestRecord $request, User $subject): void
    {
        $storedEventId = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $request->getKey())
            ->where('event_class', DsarRequestFulfilled::class)
            ->sole()
            ->id;

        $this->assertSame(1, DB::table('audit_trail')->where('subject_id', $request->getKey())->where('action', 'dsar_fulfilled')->count());
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $request->company_id,
            'stored_event_id' => $storedEventId,
            'event_class' => 'DsarRequestFulfilled',
            'subject_type' => 'dsar_request',
            'subject_id' => $request->getKey(),
            'action' => 'dsar_fulfilled',
            'description' => 'DSAR fulfilled — for: '.$subject->name,
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
        ]);
    }

    /**
     * Submitting $request queued its export — one job naming the request, its
     * company and its data subject.
     */
    protected function assertExportQueuedFor(DsarRequestRecord $request): void
    {
        Queue::assertPushed(BuildDsarExportJob::class, 1);
        Queue::assertPushed(BuildDsarExportJob::class, fn (BuildDsarExportJob $job): bool => $job->requestId === $request->getKey()
            && $job->companyId === $request->company_id
            && $job->subjectUserId === $request->subject_user_id);
    }
}
