<?php

namespace Tests\Feature\Compliance\Dsar;

use Throwable;
use ZipArchive;
use Tests\TestCase;
use App\Models\User;
use RuntimeException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Queue;
use App\Companies\Entities\CompanyUser;
use App\Companies\Enums\TransferSource;
use App\Consent\Events\ConsentRecorded;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\Constraints\SeeInOrder;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Compliance\Dsar\Events\DsarSlaBreached;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Support\Testing\Fakes\QueueFake;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\AuditTrail\Enums\AuditAction;
use Tests\Feature\PlatformAdmin\HasPlatformAdmin;
use App\Companies\Memberships\Entities\Invitation;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarDownloadExpired;
use App\Compliance\Dsar\Events\DsarRequestRejected;
use App\Compliance\Dsar\Events\DsarRequestCancelled;
use App\Compliance\Dsar\Events\DsarRequestFulfilled;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;
use App\Compliance\Dsar\IO\Export\DsarExportArchive;
use App\Compliance\Dsar\UseCases\Reports\DsarReport;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;
use App\Companies\Memberships\Aggregates\UserMembership;
use App\Compliance\Dsar\UseCases\Audit\DsarOutcomeAudit;
use App\Foundation\Notifications\Enums\NotificationType;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Compliance\Dsar\UseCases\Jobs\BuildDsarExportJob;
use Tests\Feature\Foundation\InteractsWithQueuedReactors;
use App\Compliance\Dsar\UseCases\Jobs\DeleteDsarExportFileJob;
use App\Compliance\Dsar\UseCases\Sections\DsarSectionProvider;
use App\Compliance\Dsar\Exceptions\DsarExportFileMissingException;
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
    use FreeTierCompany;
    use HasPlatformAdmin;
    use InteractsWithQueuedReactors;

    public const STAND_IN_REPORT = '%PDF stand-in';

    protected const ADMIN_IP = '198.51.100.20';

    protected const ADMIN_DEVICE = 'Lesily-Admin/2.0 (Macintosh)';

    protected const MEMBER_IP = '198.51.100.30';

    protected const MEMBER_DEVICE = 'Lesily-Member/3.1 (Android 15)';

    protected const CONTACT = [
        'name' => 'Nimal Fernando',
        'email' => 'nimal@contoso.lk',
        'phone' => '+94771234567',
        'relationship' => 'sibling',
    ];

    protected ?string $idempotencyKey;

    protected RecordingDsarReportRenderer $recordingDsarReportRenderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 09:00:00');
        config(['app.debug' => false]);
        $this->idempotencyKey = (string) Str::uuid();
        Queue::fake();
        Storage::fake('dsar');
        $this->recordingDsarReportRenderer = new RecordingDsarReportRenderer(new Filesystem, sys_get_temp_dir());
        $this->app->instance(DsarReportRendererInterface::class, $this->recordingDsarReportRenderer);
        $this->makeFreeTierCompany();
    }

    /**
     * A fresh employee of $company who has accepted no policy there.
     */
    protected function aMemberWhoNeverConsented(Company $company): User
    {
        $user = User::factory()->create();

        $company->users()->attach($user, ['role_id' => $this->seededRole('employee')->getKey()]);

        return $user;
    }

    /**
     * Who asks for $member's export: $member themselves, or Contoso's owner
     * on their behalf.
     */
    protected function aRequesterFor(User $member, string $requestedBy): User
    {
        return match ($requestedBy) {
            'themselves' => $member,
            'an owner' => $this->owner,
            default => throw new InvalidArgumentException("Unknown requester: {$requestedBy}"),
        };
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
        $this->softDeleteMembership($company, $user);

        return $user;
    }

    /**
     * A second request for $subject under a fresh idempotency key — made by the
     * subject themselves, or by Contoso's admin on their behalf.
     *
     * @return array{0: User, 1: array<string, string>}
     */
    protected function aRepeatRequestFor(User $subject, string $requester): array
    {
        $this->idempotencyKey = (string) Str::uuid();

        if ($requester === 'admin') {
            return [$this->admin, ['subjectUserId' => $subject->getKey()]];
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
                'subjectUserId' => $subject->getKey(),
                'company' => $company,
                'requestedBy' => $requester,
            ]), $this->roleCodeOf($requester, $company->getKey()))
            ->persist();

        return DsarRequestRecord::query()->findOrFail($requestId);
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
     * Every assignable tenant role — none of them reaches the platform-admin surface.
     *
     * @return array<string, array{string}>
     */
    public static function provideTenantRolesDeniedTheAdminSurface(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
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
     * @return array<string, array{string}>
     */
    public static function provideUnreadyRequests(): array
    {
        return [
            'in progress' => ['in progress'],
            'withdrawn' => ['withdrawn'],
            'rejected' => ['rejected'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonSubjectViewers(): array
    {
        return [
            'the requester acting on their behalf' => ['requester'],
            'an owner, who sees every request' => ['owner'],
            'another member' => ['member'],
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
     * @return array<string, array{string, int}>
     */
    public static function provideRequesters(): array
    {
        return [
            'their own request' => ['themselves', 1],
            'an owner\'s request for them' => ['an owner', 2],
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
        $dsarRequest = $this->aSubmittedRequest($company, $subject);
        $retrievedDsarRequest = DsarRequest::retrieve($dsarRequest->getKey());

        match ($closedBy) {
            'withdrawn' => $retrievedDsarRequest->cancel(CancelDsarRequestCommand::from([
                'dsarRequest' => $dsarRequest,
                'company' => $company,
                'cancelledBy' => $subject,
            ])),
            'fulfilled' => $retrievedDsarRequest->fulfil(FulfilDsarRequestCommand::from([
                'dsarRequest' => $dsarRequest,
                'filePath' => $this->exportPathOf($dsarRequest),
            ])),
            'rejected' => $retrievedDsarRequest->reject(RejectDsarRequestCommand::from([
                'dsarRequest' => $dsarRequest,
                'reason' => 'The export could not be built. Please submit a new request.',
            ])),
            default => throw new InvalidArgumentException("Unknown closure: {$closedBy}"),
        };
        $retrievedDsarRequest->persist();

        return $dsarRequest;
    }

    /**
     * $dsarRequest is still closed as $dsarRequestStatus: its stream holds the submission
     * and the one event that closed it — nothing recorded since.
     */
    protected function assertStillClosedAs(DsarRequestRecord $dsarRequest, DsarRequestStatus $dsarRequestStatus): void
    {
        $this->assertSame($dsarRequestStatus, $dsarRequest->fresh()?->status);
        $this->assertSame(2, LesilyStoredEvent::query()->where('aggregate_uuid', $dsarRequest->getKey())->count());
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
     * $dsarRequest's row in the list carries every resource field as recorded,
     * with $downloadAvailable stated by the test.
     */
    protected function assertListedRowShows(TestResponse $response, DsarRequestRecord $dsarRequest, bool $downloadAvailable): void
    {
        $response->assertOk();
        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json('data');
        $this->assertSame($this->expectedRow($dsarRequest, $downloadAvailable), collect($rows)->firstWhere('id', $dsarRequest->getKey()));
    }

    /**
     * The detail answered with $dsarRequest — every resource field as recorded,
     * with $downloadAvailable stated by the test.
     */
    protected function assertDsarRequestShown(TestResponse $response, DsarRequestRecord $dsarRequest, bool $downloadAvailable): void
    {
        $response->assertOk();
        $this->assertSame($this->expectedRow($dsarRequest, $downloadAvailable), $response->json('data'));
    }

    /**
     * Every resource field of $dsarRequest as recorded, with $downloadAvailable
     * stated by the test.
     *
     * @return array<string, mixed>
     */
    protected function expectedRow(DsarRequestRecord $dsarRequest, bool $downloadAvailable): array
    {
        return [
            'id' => $dsarRequest->getKey(),
            'status' => $dsarRequest->status->value,
            'request_type' => 'access',
            'subject' => ['id' => $dsarRequest->subject->getKey(), 'name' => $dsarRequest->subject->name],
            'requested_by' => ['id' => $dsarRequest->requestedBy->getKey(), 'name' => $dsarRequest->requestedBy->name],
            'requested_at' => $dsarRequest->requested_at->toISOString(),
            'sla_deadline' => $dsarRequest->requested_at->addDays(30)->toISOString(),
            'completed_at' => $dsarRequest->completed_at?->toISOString(),
            'download_available' => $downloadAvailable,
            'download_expires_at' => $dsarRequest->download_expires_at?->toISOString(),
            'cancelled_at' => $dsarRequest->cancelled_at?->toISOString(),
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
     * An authenticated member downloads one DSAR export in $company's context.
     */
    protected function downloadDsarExport(User $actor, Company $company, string $requestId): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/dsar-requests/'.$requestId.'/download', ['X-Company-Context' => $company->getKey()]);
    }

    /**
     * A member of $company who is not the request's subject: $requester
     * itself, an owner, or another member.
     */
    protected function aNonSubjectViewer(string $kind, Company $company, User $requester): User
    {
        return match ($kind) {
            'requester' => $requester,
            'owner' => $this->owner,
            'member' => $this->aCompanyMember($company),
            default => throw new InvalidArgumentException("Unknown viewer: {$kind}"),
        };
    }

    /**
     * $subject's own request in $company that is not fulfilled: still in
     * progress, withdrawn, or rejected.
     */
    protected function anUnreadyRequest(string $state, Company $company, User $subject): DsarRequestRecord
    {
        return $state === 'in progress'
            ? $this->aSubmittedRequest($company, $subject)
            : $this->aClosedRequest($company, $subject, $state);
    }

    /**
     * The download was refused because the export is not ready — 409
     * DSAR_NOT_READY with the UC-1-070 5a message.
     */
    protected function assertRefusedAsNotReady(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::DsarNotReady);
        $response->assertJsonPath('message', 'This data export is not ready to download.');
    }

    /**
     * The download was refused because its seven-day window has closed —
     * 410 DSAR_DOWNLOAD_EXPIRED with the UC-1-070 5b message.
     */
    protected function assertRefusedAsExpired(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_GONE, ApiErrorCode::DsarDownloadExpired);
        $response->assertJsonPath('message', 'This data export has expired. Request a fresh export.');
    }

    /**
     * $dsarRequest's export ZIP was served as a download named for the day it
     * was fulfilled, byte for byte the file on the dsar disk.
     */
    protected function assertExportServed(TestResponse $response, DsarRequestRecord $dsarRequest): void
    {
        $response->assertOk();
        $response->assertDownload('lesily-data-export-2026-10-01.zip');
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertSame(Storage::disk('dsar')->get($this->exportPathOf($dsarRequest)), $response->streamedContent());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideLostExportFiles(): array
    {
        return [
            'the file deleted from the disk' => ['deleted'],
            'no file path recorded' => ['unrecorded'],
        ];
    }

    /**
     * $dsarRequest's export ZIP is lost: deleted from the dsar disk, or its path
     * never recorded on the row.
     */
    protected function loseTheExportFile(string $how, DsarRequestRecord $dsarRequest): void
    {
        match ($how) {
            'deleted' => Storage::disk('dsar')->delete($this->exportPathOf($dsarRequest)),
            'unrecorded' => DsarRequestRecord::query()->whereKey($dsarRequest->getKey())->update(['file_path' => null]),
            default => throw new InvalidArgumentException("Unknown loss: {$how}"),
        };
    }

    /**
     * The download failed as a server fault — 500 INTERNAL_ERROR, the missing
     * export file reported for operations.
     */
    protected function assertFailedAsMissingFile(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError);
        Exceptions::assertReported(DsarExportFileMissingException::class);
    }

    /**
     * The download is kept out of every cache and is never content-sniffed.
     */
    protected function assertServedPrivately(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * $dsarRequest's export was downloaded — and audited — exactly $times times.
     */
    protected function assertDownloadsAudited(DsarRequestRecord $dsarRequest, int $times): void
    {
        $this->assertSame($times, DB::table('audit_trail')->where('subject_id', $dsarRequest->getKey())->where('action', 'dsar_downloaded')->count());
    }

    /**
     * The download $response served was audited as a read of $subject's
     * personal data: one dsar_downloaded row carrying the response's
     * Correlation-Id, no stored event, the subject as actor, the client's
     * address and user agent.
     */
    protected function assertDownloadAudited(TestResponse $response, DsarRequestRecord $dsarRequest, User $subject): void
    {
        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $auditEntry = AuditEntry::query()->where('request_id', $correlationId)->sole();

        $this->assertDatabaseHas('audit_trail', [
            'id' => $auditEntry->getKey(),
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => null,
            'event_class' => 'DsarExportDownloaded',
            'subject_type' => 'dsar_request',
            'subject_id' => $dsarRequest->getKey(),
            'subject_label' => null,
            'action' => 'dsar_downloaded',
            'description' => 'DSAR export downloaded — by: '.$subject->name,
            'actor_user_id' => $subject->getKey(),
            'actor_role_code' => $this->roleCodeOf($subject, $dsarRequest->company_id),
            'actor_label' => $subject->name,
            'metadata' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'recorded_at' => now(),
        ]);
    }

    /**
     * A guest (no Sanctum session) downloads one DSAR export.
     */
    protected function downloadDsarExportAsGuest(string $requestId): TestResponse
    {
        return $this->getJson('/api/v1/dsar-requests/'.$requestId.'/download');
    }

    /**
     * $subject's request in $company, fulfilled by its export job: the ZIP is
     * on the dsar disk and the download window runs seven days from now.
     */
    protected function aReadyExportFor(Company $company, User $subject, ?User $requester = null): DsarRequestRecord
    {
        $dsarRequest = $this->aSubmittedRequest($company, $subject, $requester);
        $this->runTheExportFor($dsarRequest);

        return $dsarRequest->refresh();
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
    protected function queryString(array $query): string
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
     * The request stayed hidden — the plain 404 envelope, nothing in the body
     * that names $requestId (the random correlation id aside).
     */
    protected function assertHiddenAsNotFound(TestResponse $response, string $requestId): void
    {
        $this->assertResourceNotFound($response);
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
     * one projected row and one `dsar_requested` audit row (the total is the caller's).
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
        $this->assertSame(1, AuditEntry::query()
            ->where('company_id', $company->getKey())
            ->where('action', AuditAction::DsarRequested)
            ->count());
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
     * $dsarRequest was withdrawn exactly once — one DsarRequestCancelled on its
     * stream and one withdrawal audited — and stays cancelled.
     */
    protected function assertWithdrawnOnce(DsarRequestRecord $dsarRequest): void
    {
        $this->assertSame(DsarRequestStatus::Cancelled, $dsarRequest->fresh()?->status);
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarRequestCancelled::class)
            ->count());
        $this->assertSame(1, DB::table('audit_trail')->where('subject_id', $dsarRequest->getKey())->where('action', 'dsar_withdrawn')->count());
    }

    /**
     * The withdrawal was recorded the event-sourced way: the request answered
     * as cancelled, its row stamped with when and by whom, and one
     * DsarRequestCancelled naming the actor on its stream.
     */
    protected function assertWithdrawn(TestResponse $response, DsarRequestRecord $dsarRequest, User $actor): void
    {
        $withdrawnDsarRequest = $dsarRequest->fresh() ?? $dsarRequest;
        $this->assertDsarRequestShown($response, $withdrawnDsarRequest, false);
        $this->assertSame(
            [DsarRequestStatus::Cancelled, now()->toISOString(), $actor->getKey()],
            [$withdrawnDsarRequest->status, $withdrawnDsarRequest->cancelled_at?->toISOString(), $withdrawnDsarRequest->cancelled_by],
        );
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', DsarRequestCancelled::class)
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->whereForCompany($dsarRequest->company_id)
            ->where('event_properties->cancelledByUserId', $actor->getKey())
            ->count());
    }

    /**
     * Exactly one audit entry records the withdrawal $response answered — "DSAR
     * withdrawn — for: <subject>, by: <actor>" — linked to its stored event,
     * stamped with the actor's role, the response's Correlation-Id and the
     * client's address and user agent.
     */
    protected function assertWithdrawalAudited(TestResponse $response, User $subject, User $actor): void
    {
        $dsarRequest = $this->dsarRequestIn($response);
        $storedEventId = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarRequestCancelled::class)
            ->sole()
            ->getKey();
        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $auditEntry = AuditEntry::query()->where('request_id', $correlationId)->sole();

        $this->assertSame(1, DB::table('audit_trail')->where('subject_id', $dsarRequest->getKey())->where('action', 'dsar_withdrawn')->count());
        $this->assertDatabaseHas('audit_trail', [
            'id' => $auditEntry->getKey(),
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => $storedEventId,
            'event_class' => 'DsarRequestCancelled',
            'subject_type' => 'dsar_request',
            'subject_id' => $dsarRequest->getKey(),
            'action' => 'dsar_withdrawn',
            'description' => 'DSAR withdrawn — for: '.$subject->name.', by: '.$actor->name,
            'actor_user_id' => $actor->getKey(),
            'actor_role_code' => $this->roleCodeOf($actor, $dsarRequest->company_id),
            'actor_label' => $actor->name,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
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
     * $replayResponse is the remembered answer to $firstResponse — same status and body,
     * flagged as replayed.
     */
    protected function assertReplayedOf(TestResponse $replayResponse, TestResponse $firstResponse): void
    {
        $replayResponse->assertCreated();
        $replayResponse->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($firstResponse->getContent(), $replayResponse->getContent());
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
        $dsarRequest = $this->dsarRequestIn($response);
        $response->assertJsonPath('data', [
            'id' => $dsarRequest->getKey(),
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
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_properties->companyId', $dsarRequest->company_id)
            ->where('event_properties->subjectUserId', $subject->getKey())
            ->where('event_properties->requestedByUserId', $requester->getKey())
            ->where('event_properties->requestType', 'access')
            ->count());
        $this->assertSame($this->roleCodeOf($requester, $dsarRequest->company_id), $dsarRequest->requested_by_role);
    }

    /**
     * The request's audit row records it — "DSAR requested — for:
     * <subject>, by: <requester>" — linked to its stored event, stamped with
     * the requester's role, the response's Correlation-Id and the client's
     * address and user agent.
     */
    protected function assertDsarRequestAudited(TestResponse $response, User $subject, User $requester): void
    {
        $dsarRequest = $this->dsarRequestIn($response);
        $storedEventId = LesilyStoredEvent::query()->where('aggregate_uuid', $dsarRequest->getKey())->sole()->getKey();
        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);
        $auditEntry = AuditEntry::query()->where('request_id', $correlationId)->sole();

        $this->assertDatabaseHas('audit_trail', [
            'id' => $auditEntry->getKey(),
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => $storedEventId,
            'event_class' => 'DsarRequestSubmitted',
            'subject_type' => 'dsar_request',
            'subject_id' => $dsarRequest->getKey(),
            'action' => 'dsar_requested',
            'description' => 'DSAR requested — for: '.$subject->name.', by: '.$requester->name,
            'actor_user_id' => $requester->getKey(),
            'actor_role_code' => $this->roleCodeOf($requester, $dsarRequest->company_id),
            'actor_label' => $requester->name,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
        ]);
    }

    protected function dsarRequestIn(TestResponse $response): DsarRequestRecord
    {
        $requestId = $response->json('data.id');
        $this->assertIsString($requestId);

        return DsarRequestRecord::query()->findOrFail($requestId);
    }

    protected function roleCodeOf(User $member, string $companyId): string
    {
        $roleId = CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $member->getKey())
            ->value('role_id');

        return Role::query()->whereKey($roleId)->value('code');
    }

    /**
     * A refused or rolled-back change left $dsarRequest as it was — still in
     * progress, its stream holding only the submission, no withdrawal audited.
     */
    protected function assertStillInProgress(DsarRequestRecord $dsarRequest): void
    {
        $this->assertSame(DsarRequestStatus::InProgress, $dsarRequest->fresh()?->status);
        $this->assertSame(1, LesilyStoredEvent::query()->where('aggregate_uuid', $dsarRequest->getKey())->count());
        $this->assertSame(0, DB::table('audit_trail')->where('subject_id', $dsarRequest->getKey())->count());
    }

    /**
     * A refused submission left no trace in Contoso — its stream holds the
     * ready-made events beside the members' consents, no request projected,
     * no audit row beyond the ready-made ones.
     */
    protected function assertNoDsarRequestRecorded(): void
    {
        $this->assertSame(self::READY_MADE_NON_CONSENT_EVENTS, LesilyStoredEvent::query()
            ->whereForCompany($this->company->getKey())
            ->where('event_class', '!=', ConsentRecorded::class)
            ->count());
        $this->assertSame(0, DsarRequestRecord::query()->where('company_id', $this->company->getKey())->count());
        $this->assertSame(self::READY_MADE_AUDIT_ROWS, AuditEntry::query()->where('company_id', $this->company->getKey())->count());
    }

    /**
     * The export collects exactly $dsarSectionProviders — the container hands the job
     * this list instead of the registered one.
     */
    protected function withSections(DsarSectionProvider ...$dsarSectionProviders): void
    {
        $this->app->instance(DsarSections::class, new DsarSections(array_values($dsarSectionProviders)));
    }

    /**
     * A section that withdraws $dsarRequest, as its requester, while the export
     * is being collected — the race a worker can lose.
     */
    protected function aSectionThatWithdraws(DsarRequestRecord $dsarRequest): DsarSectionProvider
    {
        return new class($dsarRequest) implements DsarSectionProvider
        {
            public function __construct(private readonly DsarRequestRecord $dsarRequest) {}

            public function key(): string
            {
                return 'withdraws';
            }

            public function collect(string $subjectUserId, string $companyId): array
            {
                DsarRequest::retrieve($this->dsarRequest->getKey())
                    ->cancel(CancelDsarRequestCommand::from([
                        'dsarRequest' => $this->dsarRequest,
                        'company' => Company::query()->findOrFail($companyId),
                        'cancelledBy' => User::query()->findOrFail($this->dsarRequest->requested_by),
                    ]))
                    ->persist();

                return [];
            }
        };
    }

    /**
     * Nothing was fulfilled for $dsarRequest: no DsarRequestFulfilled on its
     * stream, no fulfilment audited, no export file left, and its status is
     * still $dsarRequestStatus.
     */
    protected function assertNothingFulfilled(DsarRequestRecord $dsarRequest, DsarRequestStatus $dsarRequestStatus): void
    {
        $this->assertSame($dsarRequestStatus, $dsarRequest->fresh()?->status);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarRequestFulfilled::class)
            ->count());
        $this->assertDatabaseMissing('audit_trail', ['subject_id' => $dsarRequest->getKey(), 'action' => 'dsar_fulfilled']);
        Storage::disk('dsar')->assertMissing('dsar/'.$dsarRequest->company_id.'/'.$dsarRequest->getKey().'.zip');
    }

    /**
     * The half-written ZIP a failed try leaves on the dsar disk for $dsarRequest.
     */
    protected function aPartialExportFor(DsarRequestRecord $dsarRequest): void
    {
        Storage::disk('dsar')->put($this->exportPathOf($dsarRequest), 'partial');
    }

    /**
     * The worker gave up on $dsarRequest's export: failed() runs once, after the
     * last try, with the error that ended it.
     */
    protected function failTheExportFor(DsarRequestRecord $dsarRequest, Throwable $throwable): void
    {
        (new BuildDsarExportJob($dsarRequest->getKey(), $dsarRequest->company_id, $dsarRequest->subject_user_id))->failed($throwable);
    }

    /**
     * $dsarRequest was rejected the event-sourced way: one DsarRequestRejected on
     * its stream, the row rejected now with the fixed reason — never the error
     * — and no export file left.
     */
    protected function assertRejected(DsarRequestRecord $dsarRequest): void
    {
        $rejectedDsarRequest = $dsarRequest->fresh() ?? $dsarRequest;

        $this->assertSame(
            [DsarRequestStatus::Rejected, now()->toISOString(), 'The export could not be built. Please submit a new request.'],
            [$rejectedDsarRequest->status, $rejectedDsarRequest->rejected_at?->toISOString(), $rejectedDsarRequest->rejection_reason],
        );
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', DsarRequestRejected::class)
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->whereForCompany($dsarRequest->company_id)
            ->count());
        Storage::disk('dsar')->assertMissing($this->exportPathOf($dsarRequest));
    }

    /**
     * Exactly one audit entry records the rejection — "DSAR rejected — for:
     * <subject>" — linked to its stored event, with no actor (ERD-1-11).
     */
    protected function assertRejectionAudited(DsarRequestRecord $dsarRequest, User $subject): void
    {
        $storedEventId = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarRequestRejected::class)
            ->sole()
            ->getKey();

        $this->assertSame(1, DB::table('audit_trail')->where('subject_id', $dsarRequest->getKey())->where('action', 'dsar_rejected')->count());
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => $storedEventId,
            'event_class' => 'DsarRequestRejected',
            'subject_type' => 'dsar_request',
            'subject_id' => $dsarRequest->getKey(),
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
     * Nothing was rejected for $dsarRequest: no DsarRequestRejected on its stream,
     * no rejection audited, no export file left, and its status is still
     * $dsarRequestStatus.
     */
    protected function assertNothingRejected(DsarRequestRecord $dsarRequest, DsarRequestStatus $dsarRequestStatus): void
    {
        $this->assertSame($dsarRequestStatus, $dsarRequest->fresh()?->status);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarRequestRejected::class)
            ->count());
        $this->assertDatabaseMissing('audit_trail', ['subject_id' => $dsarRequest->getKey(), 'action' => 'dsar_rejected']);
        Storage::disk('dsar')->assertMissing($this->exportPathOf($dsarRequest));
    }

    /**
     * A failed change left $dsarRequest still in progress with nothing recorded
     * since, and no partial export file at its path.
     */
    protected function assertStillInProgressWithoutExportFile(DsarRequestRecord $dsarRequest): void
    {
        $this->assertStillInProgress($dsarRequest);
        Storage::disk('dsar')->assertMissing($this->exportPathOf($dsarRequest));
    }

    /**
     * A sibling run of $dsarRequest's export fulfils it in the instant before this
     * run's reject lands — the reject then finds the request already closed.
     */
    protected function aSiblingRunFulfilsBeforeTheReject(DsarRequestRecord $dsarRequest): void
    {
        $this->app->instance(RejectDsarRequestInteractor::class, new class(app(DsarOutcomeAudit::class), $dsarRequest) extends RejectDsarRequestInteractor
        {
            public function __construct(DsarOutcomeAudit $dsarOutcomeAudit, private readonly DsarRequestRecord $dsarRequest)
            {
                parent::__construct($dsarOutcomeAudit);
            }

            public function execute(RejectDsarRequestCommand $command): void
            {
                DsarRequest::retrieve($this->dsarRequest->getKey())
                    ->fulfil(FulfilDsarRequestCommand::from([
                        'dsarRequest' => $this->dsarRequest,
                        'filePath' => 'dsar/'.$this->dsarRequest->company_id.'/'.$this->dsarRequest->getKey().'.zip',
                    ]))
                    ->persist();

                parent::execute($command);
            }
        });
    }

    /**
     * $dsarRequest is still closed as $dsarRequestStatus with nothing recorded since, and no
     * export file is left at its path — no personal data outlives it (N27).
     */
    protected function assertStillClosedWithoutExportFile(DsarRequestRecord $dsarRequest, DsarRequestStatus $dsarRequestStatus): void
    {
        $this->assertStillClosedAs($dsarRequest, $dsarRequestStatus);
        Storage::disk('dsar')->assertMissing($this->exportPathOf($dsarRequest));
    }

    /**
     * $dsarRequest is still fulfilled with nothing recorded since, and the export
     * planted at its path is still there, unchanged.
     */
    protected function assertStillFulfilledWithExportKept(DsarRequestRecord $dsarRequest): void
    {
        $this->assertStillClosedAs($dsarRequest, DsarRequestStatus::Fulfilled);
        $this->assertSame('partial', Storage::disk('dsar')->get($this->exportPathOf($dsarRequest)));
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
            public function render(DsarReport $dsarReport): string
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
     * $buildDsarExportJob gives the worker three tries, waiting one minute and then five
     * before the last, on the default queue (ruling N24).
     */
    protected function assertTriesThreeTimesWithBackoff(BuildDsarExportJob $buildDsarExportJob): void
    {
        $this->assertSame(3, $buildDsarExportJob->tries);
        $this->assertSame([60, 300], $buildDsarExportJob->backoff);
        $this->assertNull($buildDsarExportJob->queue);
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
     * $user signs in with their password — the access log records it.
     */
    protected function signInThroughTheApi(User $user): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    /**
     * $user tries to sign in with a wrong password — the access log records
     * the failure and its reason.
     */
    protected function signInWithAWrongPassword(User $user): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'not-their-password']);
    }

    /**
     * $member looks up a DSAR request that does not exist in $company — the
     * access log records the refusal.
     */
    protected function lookUpAMissingRequest(User $member, Company $company): void
    {
        $this->viewDsarRequest($member, $company, (string) Str::uuid7());
    }

    /**
     * Delivers the notices $eventClass leaves behind: none was sent inline,
     * then the captured reactor runs as a worker would. The caller arms
     * Notification::fake() before acting, so an inline send is seen.
     */
    protected function deliverTheNoticesOf(string $eventClass): void
    {
        $this->assertReactorDeferred($eventClass, SystemNotification::class);
        $this->runQueuedHandlersFor($eventClass);
    }

    /**
     * $dsarRequest's subject and its requester were each sent one $notificationType notice
     * about the request's company, and $total notices went out in all.
     */
    protected function assertNoticeSentOnceEach(DsarRequestRecord $dsarRequest, NotificationType $notificationType, int $total): void
    {
        $dsarRequest->load(['subject', 'requestedBy']);
        $company = Company::query()->findOrFail($dsarRequest->company_id);

        foreach ([$dsarRequest->subject, $dsarRequest->requestedBy] as $recipient) {
            Notification::assertSentToTimes($recipient, SystemNotification::class, 1);
            Notification::assertSentTo($recipient, SystemNotification::class, fn (SystemNotification $systemNotification): bool => $systemNotification->type === $notificationType
                && $systemNotification->companyName === $company->name
                && $systemNotification->companyId === $company->getKey());
        }
        Notification::assertCount($total);
    }

    /**
     * A $notificationType notice sent to $recipient about $company — or about their whole
     * account when $company is null — as the production writers send it.
     */
    protected function aNotificationFor(User $recipient, NotificationType $notificationType, ?Company $company): void
    {
        $recipient->notify(new SystemNotification(
            $notificationType,
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
                'company' => $company,
                'email' => $email,
                'role' => $this->seededRole('employee'),
                'invitedBy' => User::factory()->create(),
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
        $invitation = Invitation::query()->with('company')->findOrFail($invitationId);

        UserMembership::retrieve($invitationId)
            ->invite(CreateInvitationCommand::from([
                'company' => $invitation->company,
                'email' => $invitation->email,
                'role' => $this->seededRole($roleCode),
                'invitedBy' => User::factory()->create(),
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
                'resentBy' => User::factory()->create(),
            ]))
            ->persist();
    }

    protected function revokeTheInvitation(string $invitationId): void
    {
        UserMembership::retrieve($invitationId)
            ->revokeInvitation(RevokeInvitationCommand::from([
                'company' => Invitation::query()->findOrFail($invitationId)->company()->sole(),
                'invitationId' => $invitationId,
                'revokedBy' => User::factory()->create(),
                'reason' => 'Sent to the wrong team',
            ]))
            ->persist();
    }

    /**
     * $owner hands $company to $newOwner through the self-service transfer, recorded
     * the event-sourced way.
     */
    protected function anOwnershipTransfer(Company $company, User $owner, User $newOwner): void
    {
        CompanyAggregate::retrieve($company->getKey())
            ->transferOwnership(TransferCompanyOwnershipCommand::from([
                'newOwnerUserId' => $newOwner->getKey(),
                'transferredBy' => $owner,
                'source' => TransferSource::SelfService,
            ]), $owner->getKey())
            ->persist();
    }

    /**
     * An admin of $company — Contoso's own on Contoso, a fresh one elsewhere —
     * makes $member an HR officer through the role-change endpoint, from the
     * admin's own network address and device.
     */
    protected function anAdminChangesTheRoleOf(User $member, Company $company): void
    {
        $membershipId = $this->membershipIdOf($company, $member);
        $admin = $company->is($this->company) ? $this->admin : $this->aCompanyMember($company, 'admin');
        Sanctum::actingAs($admin);

        $this->withHeader('User-Agent', self::ADMIN_DEVICE)
            ->withServerVariables(['REMOTE_ADDR' => self::ADMIN_IP])
            ->patchJson(
                "/api/v1/memberships/{$membershipId}",
                ['roleCode' => 'hr_officer'],
                ['X-Company-Context' => $company->getKey()],
            )
            ->assertOk();

        $this->withoutHeader('User-Agent')->withServerVariables([]);
    }

    /**
     * $owner sets $company's emergency contact to CONTACT through the
     * endpoint, from their own network address and device.
     */
    protected function theySetTheirEmergencyContact(User $owner, Company $company): void
    {
        Sanctum::actingAs($owner);

        $this->withHeader('User-Agent', self::MEMBER_DEVICE)
            ->withServerVariables(['REMOTE_ADDR' => self::MEMBER_IP])
            ->putJson("/api/v1/companies/{$company->getKey()}/emergency-contact", self::CONTACT)
            ->assertOk();

        $this->withoutHeader('User-Agent')->withServerVariables([]);
    }

    /**
     * The audit section of $dsarRequest's export.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function auditSectionOf(DsarRequestRecord $dsarRequest): array
    {
        $section = $this->exportedData($dsarRequest)['audit_trail'];
        $this->assertIsArray($section);

        return $section;
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
                    'changedBy' => User::factory()->create(),
                ]),
                $this->seededRole('employee')->getKey(),
                $this->seededRole($roleCode)->getKey(),
            )
            ->persist();
    }

    /**
     * Support hands $company from $owner to $newOwner (the admin-assisted path),
     * recorded the event-sourced way.
     */
    protected function aSupportAssistedTransfer(Company $company, User $owner, User $newOwner): void
    {
        CompanyAggregate::retrieve($company->getKey())
            ->transferOwnership(TransferCompanyOwnershipCommand::from([
                'newOwnerUserId' => $newOwner->getKey(),
                'transferredBy' => User::factory()->create(),
                'source' => TransferSource::Admin,
            ]), $owner->getKey())
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
     * The sections $dsarRequest's export holds — data.json decoded from its ZIP
     * on the dsar disk.
     *
     * @return array<string, mixed>
     */
    protected function exportedData(DsarRequestRecord $dsarRequest): array
    {
        return json_decode($this->exportedJsonOf($dsarRequest), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * data.json exactly as $dsarRequest's export ZIP holds it.
     */
    protected function exportedJsonOf(DsarRequestRecord $dsarRequest): string
    {
        $zipArchive = $this->exportZipOf($dsarRequest);
        $json = (string) $zipArchive->getFromName('data.json');
        $zipArchive->close();

        return $json;
    }

    /**
     * $dsarRequest's export ZIP holds exactly data.json and report.pdf, the
     * report being the bytes the renderer produced.
     */
    protected function assertExportHoldsDataAndReport(DsarRequestRecord $dsarRequest): void
    {
        $zipArchive = $this->exportZipOf($dsarRequest);
        $entries = array_map(fn (int $index): string => (string) $zipArchive->getNameIndex($index), range(0, $zipArchive->numFiles - 1));
        $report = $zipArchive->getFromName('report.pdf');
        $zipArchive->close();

        $this->assertSame(['data.json', 'report.pdf'], $entries);
        $this->assertSame(self::STAND_IN_REPORT, $report);
    }

    /**
     * The report opens with the Lesily cover — whose data, which company,
     * which request, when — and then the guide to its two parts, in order.
     */
    protected function assertReportCover(DsarRequestRecord $dsarRequest, User $subject, Company $company): void
    {
        $this->assertReportShowsInOrder([
            'lesily',
            'Your personal data',
            $subject->name,
            $subject->email,
            $company->name,
            $dsarRequest->getKey(),
            'Requested',
            '1 Oct 2026, 09:00 UTC',
            'Generated',
            '1 Oct 2026, 11:00 UTC',
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
        $this->assertStringNotContainsString('dsar.', $this->recordingDsarReportRenderer->html);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int, int|string>
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
        $this->assertStringContainsString('<td>'.e($text).'</td>', $this->recordingDsarReportRenderer->html);
    }

    /**
     * @param  array<int, string>  $texts
     */
    protected function assertReportShowsInOrder(array $texts): void
    {
        $this->assertThat(array_map(e(...), $texts), new SeeInOrder($this->recordingDsarReportRenderer->html));
    }

    protected function exportZipOf(DsarRequestRecord $dsarRequest): ZipArchive
    {
        $path = Storage::disk('dsar')->path($this->exportPathOf($dsarRequest));
        $zipArchive = new ZipArchive;
        $this->assertTrue($zipArchive->open($path), "The export ZIP at {$path} could not be opened.");

        return $zipArchive;
    }

    /**
     * The archive builds its ZIP in a fresh, empty directory of its own —
     * returned so a test can see what the build leaves there.
     */
    protected function anArchiveWorkingInAnEmptyDirectory(Filesystem $filesystem = new Filesystem): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dsar-work-'.Str::uuid7()->toString();
        mkdir($directory);
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($directory));
        $this->app->instance(DsarExportArchive::class, new DsarExportArchive($filesystem, $directory));

        return $directory;
    }

    /**
     * Another process already holds a file at the name the archive will pick
     * for its ZIP, so the archive cannot create it there — returned so a test
     * can see that file.
     */
    protected function anArchiveWhoseNameIsAlreadyTaken(): string
    {
        $directory = $this->anArchiveWorkingInAnEmptyDirectory();
        $takenFile = $directory.DIRECTORY_SEPARATOR.Str::freezeUuids().'.zip';
        $this->beforeApplicationDestroyed(fn () => Str::createUuidsNormally());
        touch($takenFile);

        return $takenFile;
    }

    /**
     * A filesystem shared with another worker that is handed every name the
     * moment it is freed: each deleted file is created again at once.
     */
    protected function aFilesystemWhereAnotherWorkerTakesEveryFreedName(): Filesystem
    {
        return new class extends Filesystem
        {
            public function delete($paths): bool
            {
                $deleted = parent::delete($paths);
                foreach ((array) $paths as $path) {
                    touch($path);
                }

                return $deleted;
            }
        };
    }

    /**
     * $dsarRequest's ZIP is on the dsar disk and the archive's working directory
     * holds nothing — no temporary copy of the person's data survives.
     */
    protected function assertExportBuiltWithoutLeftovers(DsarRequestRecord $dsarRequest, string $workDirectory): void
    {
        Storage::disk('dsar')->assertExists($this->exportPathOf($dsarRequest));
        $this->assertSame([], (new Filesystem)->files($workDirectory));
    }

    /**
     * Every section of $exported belongs to $company alone: $member's
     * membership of it, the consent they gave in it, the audit entries they
     * acted in there or that are about them, and — of its invitations, transfers, cases, notices and
     * refusals — only the pending invitation, the transfer that made them
     * owner, the open case naming them, the notice about $company and the
     * lookup refused there.
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
            [['role_changed', 'user'], ['dsar_requested', 'dsar_request']],
            array_map(fn (array $entry): array => [$entry['action'], $entry['subject_type']], $exported['audit_trail']),
        );
        $this->assertSame(
            [[1], ['new_owner'], ['open'], [$company->name], ['not_found']],
            [
                array_map(fn (array $invitation): int => count($invitation['timeline']), $exported['invitations']),
                array_column($exported['ownership_transfers']['transfers'], 'role'),
                array_column($exported['ownership_transfers']['cases'], 'status'),
                array_column($exported['notifications']['notifications'], 'company_name'),
                array_column($exported['access_log'], 'access_event'),
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
        $this->assertStringContainsString(e($user->email), $this->recordingDsarReportRenderer->html);
        $this->assertNoSecretIn($this->recordingDsarReportRenderer->html, $user, $otherSecrets);
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

    protected function exportPathOf(DsarRequestRecord $dsarRequest): string
    {
        return 'dsar/'.$dsarRequest->company_id.'/'.$dsarRequest->getKey().'.zip';
    }

    /**
     * Runs $dsarRequest's export job the way a worker would — handle() with its
     * dependencies resolved from the container.
     */
    protected function runTheExportFor(DsarRequestRecord $dsarRequest): void
    {
        app()->call([new BuildDsarExportJob($dsarRequest->getKey(), $dsarRequest->company_id, $dsarRequest->subject_user_id), 'handle']);
    }

    /**
     * $dsarRequest was fulfilled the event-sourced way: one DsarRequestFulfilled
     * naming its file, the row fulfilled now with a download window of seven
     * days, and the export ZIP on the private dsar disk.
     */
    protected function assertFulfilled(DsarRequestRecord $dsarRequest): void
    {
        $filePath = 'dsar/'.$dsarRequest->company_id.'/'.$dsarRequest->getKey().'.zip';
        $fulfilledDsarRequest = $dsarRequest->fresh() ?? $dsarRequest;

        $this->assertSame(
            [DsarRequestStatus::Fulfilled, now()->toISOString(), $filePath, now()->addDays(7)->toISOString()],
            [$fulfilledDsarRequest->status, $fulfilledDsarRequest->completed_at?->toISOString(), $fulfilledDsarRequest->file_path, $fulfilledDsarRequest->download_expires_at?->toISOString()],
        );
        $this->assertSame(1, LesilyStoredEvent::query()
            ->where('event_class', DsarRequestFulfilled::class)
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->whereForCompany($dsarRequest->company_id)
            ->where('event_properties->filePath', $filePath)
            ->count());
        Storage::disk('dsar')->assertExists($filePath);
    }

    /**
     * Runs the deferred expiry handlers the way a worker would, then each
     * file deletion they queued — nothing is deleted while the scan runs.
     */
    protected function deleteTheExpiredFiles(): void
    {
        Queue::assertNotPushed(DeleteDsarExportFileJob::class);
        $this->runQueuedHandlersFor(DsarDownloadExpired::class);

        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);

        $deletionCollection = $queue->pushed(DeleteDsarExportFileJob::class);
        $this->assertNotEmpty($deletionCollection);
        $deletionCollection->each(fn (DeleteDsarExportFileJob $deleteDsarExportFileJob) => app()->call([$deleteDsarExportFileJob, 'handle']));
    }

    /**
     * $dsarRequest's download was expired at $expiredAt the event-sourced way: still
     * fulfilled, its row stamped $expiredAt, and exactly one DsarDownloadExpired on
     * its stream carrying $expiredAt.
     */
    protected function assertDownloadExpired(DsarRequestRecord $dsarRequest, CarbonInterface $expiredAt): void
    {
        $expiredDsarRequest = $dsarRequest->fresh() ?? $dsarRequest;
        $eloquentStoredEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarDownloadExpired::class)
            ->sole();

        $this->assertSame(
            [DsarRequestStatus::Fulfilled, $expiredAt->toISOString(), $expiredAt->toISOString()],
            [$expiredDsarRequest->status, $expiredDsarRequest->download_expired_at?->toISOString(), CarbonImmutable::parse($eloquentStoredEvent->event_properties['expiredAt'])->toISOString()],
        );
    }

    /**
     * Exactly one audit entry records the expiry — "DSAR download expired —
     * for: <subject>" — linked to its stored event, with no actor: the daily
     * scan is a system process (ERD-1-11).
     */
    protected function assertExpiryAudited(DsarRequestRecord $dsarRequest, User $subject): void
    {
        $storedEventId = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarDownloadExpired::class)
            ->sole()
            ->getKey();

        $this->assertSame(1, DB::table('audit_trail')->where('subject_id', $dsarRequest->getKey())->where('action', 'dsar_download_expired')->count());
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => $storedEventId,
            'event_class' => 'DsarDownloadExpired',
            'subject_type' => 'dsar_request',
            'subject_id' => $dsarRequest->getKey(),
            'action' => 'dsar_download_expired',
            'description' => 'DSAR download expired — for: '.$subject->name,
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
        ]);
    }

    /**
     * Makes auditing $dsarRequest's outcome fail inside its transaction — every
     * other request's outcome records as usual — and returns the failure.
     */
    protected function failTheAuditOf(DsarRequestRecord $dsarRequest): RuntimeException
    {
        Exceptions::fake();
        $runtimeException = new RuntimeException('Induced audit failure.');

        $this->app->instance(DsarOutcomeAudit::class, new class($dsarRequest, $runtimeException) extends DsarOutcomeAudit
        {
            public function __construct(private readonly DsarRequestRecord $failingDsarRequest, private readonly RuntimeException $runtimeException) {}

            public function record(DsarRequestRecord $dsarRequest, string $eventClass, AuditAction $auditAction): void
            {
                if ($dsarRequest->is($this->failingDsarRequest)) {
                    throw $this->runtimeException;
                }

                parent::record($dsarRequest, $eventClass, $auditAction);
            }
        });

        return $runtimeException;
    }

    /**
     * $dsarRequest's missed deadline was flagged at $breachedAt the event-sourced way:
     * still in progress, its row stamped $breachedAt, and exactly one DsarSlaBreached
     * on its stream carrying $breachedAt.
     */
    protected function assertBreachRecorded(DsarRequestRecord $dsarRequest, CarbonInterface $breachedAt): void
    {
        $flaggedDsarRequest = $dsarRequest->fresh() ?? $dsarRequest;
        $eloquentStoredEvent = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarSlaBreached::class)
            ->sole();

        $this->assertSame(
            [DsarRequestStatus::InProgress, $breachedAt->toISOString(), $breachedAt->toISOString()],
            [$flaggedDsarRequest->status, $flaggedDsarRequest->sla_breached_at?->toISOString(), CarbonImmutable::parse($eloquentStoredEvent->event_properties['breachedAt'])->toISOString()],
        );
    }

    /**
     * Exactly one audit entry records the missed deadline — "DSAR deadline
     * missed — for: <subject>" — linked to its stored event, with no actor:
     * the daily scan is a system process (ERD-1-11).
     */
    protected function assertBreachAudited(DsarRequestRecord $dsarRequest, User $subject): void
    {
        $storedEventId = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarSlaBreached::class)
            ->sole()
            ->getKey();

        $this->assertSame(1, DB::table('audit_trail')->where('subject_id', $dsarRequest->getKey())->where('action', 'dsar_deadline_missed')->count());
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => $storedEventId,
            'event_class' => 'DsarSlaBreached',
            'subject_type' => 'dsar_request',
            'subject_id' => $dsarRequest->getKey(),
            'action' => 'dsar_deadline_missed',
            'description' => 'DSAR deadline missed — for: '.$subject->name,
            'actor_user_id' => null,
            'actor_role_code' => null,
            'actor_label' => null,
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
        ]);
    }

    /**
     * Exactly one audit entry records the fulfilment — "DSAR fulfilled — for:
     * <subject>" — linked to its stored event, with no actor: the export job
     * is a system process (ERD-1-11).
     */
    protected function assertFulfilmentAudited(DsarRequestRecord $dsarRequest, User $subject): void
    {
        $storedEventId = LesilyStoredEvent::query()
            ->where('aggregate_uuid', $dsarRequest->getKey())
            ->where('event_class', DsarRequestFulfilled::class)
            ->sole()
            ->getKey();

        $this->assertSame(1, DB::table('audit_trail')->where('subject_id', $dsarRequest->getKey())->where('action', 'dsar_fulfilled')->count());
        $this->assertDatabaseHas('audit_trail', [
            'company_id' => $dsarRequest->company_id,
            'stored_event_id' => $storedEventId,
            'event_class' => 'DsarRequestFulfilled',
            'subject_type' => 'dsar_request',
            'subject_id' => $dsarRequest->getKey(),
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
     * Submitting $dsarRequest queued its export — one job naming the request, its
     * company and its data subject.
     */
    protected function assertExportQueuedFor(DsarRequestRecord $dsarRequest): void
    {
        Queue::assertPushed(BuildDsarExportJob::class, 1);
        Queue::assertPushed(BuildDsarExportJob::class, fn (BuildDsarExportJob $buildDsarExportJob): bool => $buildDsarExportJob->requestId === $dsarRequest->getKey()
            && $buildDsarExportJob->companyId === $dsarRequest->company_id
            && $buildDsarExportJob->subjectUserId === $dsarRequest->subject_user_id);
    }
}
