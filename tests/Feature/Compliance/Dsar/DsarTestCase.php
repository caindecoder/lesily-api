<?php

namespace Tests\Feature\Compliance\Dsar;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use App\Companies\Entities\CompanyUser;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;
use App\Compliance\Dsar\UseCases\Repositories\DsarRequestRepositoryInterface;

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
            default => (string) Str::uuid(),
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
     * The repository's open-request pre-check always answers "none", so a
     * second open request reaches the database's unique key.
     */
    protected function bypassOpenRequestPreCheck(): void
    {
        $this->app->instance(DsarRequestRepositoryInterface::class, new class implements DsarRequestRepositoryInterface
        {
            public function findOpenFor(string $companyId, string $subjectUserId): ?DsarRequestRecord
            {
                return null;
            }
        });
    }

    protected function seededRole(string $code): Role
    {
        return Role::query()->where('code', $code)->sole();
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
     * The submission was refused because an export is already being prepared
     * for the subject — 409 DSAR_IN_PROGRESS with the UC-1-070 message.
     */
    protected function assertRefusedAsInProgress(TestResponse $response): void
    {
        $this->assertRefusedWith($response, Response::HTTP_CONFLICT, ApiErrorCode::DsarInProgress);
        $response->assertJsonPath('message', 'A data export is already being prepared.');
    }

    /**
     * Exactly one request exists for $subject in $company — one submitted event
     * and one projected row, still open.
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
     * A refused submission left no trace in $company — no event stored and no
     * request projected.
     */
    protected function assertNoDsarRequestRecorded(Company $company): void
    {
        $this->assertSame(0, LesilyStoredEvent::query()->whereForCompany($company->getKey())->count());
        $this->assertSame(0, DsarRequestRecord::query()->where('company_id', $company->getKey())->count());
    }
}
