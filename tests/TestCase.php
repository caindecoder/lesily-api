<?php

namespace Tests;

use PDOException;
use Illuminate\Testing\TestResponse;
use Illuminate\Database\QueryException;
use Database\Seeders\RolesPermissionsSeeder;
use App\Foundation\Http\Responses\ApiErrorCode;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A driver-shaped QueryException for inducing DB failures at test seams:
     * errorInfo rides the PDOException exactly as MySQL raises it, and the
     * MESSAGE controls whether Laravel's concurrency detector promotes it to
     * a DeadlockException inside a nested transaction.
     *
     * @param  array{sqlstate: string, code: int, message: string}  $failure
     */
    protected function syntheticQueryException(array $failure): QueryException
    {
        $driverError = new PDOException($failure['message']);
        $driverError->errorInfo = [$failure['sqlstate'], $failure['code'], $failure['message']];

        return new QueryException('mysql', 'select 1 for update', [], $driverError);
    }

    /**
     * The lock-contention render matrix (OQ-1-164): the two contention codes
     * answer as the 409 conflict family; an unrelated driver failure must
     * pass through unclassified. The 1205 message deliberately avoids the
     * concurrency-detector strings — that is the arm production hits at top
     * level (the T3 lesson).
     *
     * @return array<string, array{0: array{sqlstate: string, code: int, message: string}, 1: ApiErrorCode}>
     */
    public static function contentionProvider(): array
    {
        return [
            'a deadlock detected mid-transaction' => [
                ['sqlstate' => '40001', 'code' => 1213, 'message' => 'Deadlock found when trying to get lock'],
                ApiErrorCode::StateGuardViolation,
            ],
            'a lock wait that timed out' => [
                ['sqlstate' => 'HY000', 'code' => 1205, 'message' => 'SQLSTATE[HY000]: General error: 1205'],
                ApiErrorCode::StateGuardViolation,
            ],
            'a deadlock reported without the detector phrase' => [
                ['sqlstate' => '40001', 'code' => 1213, 'message' => 'SQLSTATE[40001]: General error: 1213'],
                ApiErrorCode::StateGuardViolation,
            ],
            'a duplicate entry passes through unclassified' => [
                ['sqlstate' => '23000', 'code' => 1062, 'message' => 'Duplicate entry'],
                ApiErrorCode::InternalError,
            ],
        ];
    }

    /**
     * Seed the UC-1-136 RBAC catalog. Every event-sourced company genesis enrolls
     * its owner against the seeded 'owner' role (CompanyProjector), so any test
     * arranging a company through the aggregate needs the catalog in place —
     * production runs the seeder at deploy. Idempotent (firstOrCreate).
     */
    protected function seedRbacCatalog(): void
    {
        RolesPermissionsSeeder::fromDefaultPaths()->run();
    }

    /**
     * The company-scope claim was rejected by AuthorizeCompany — 403 envelope
     * with COMPANY_ACCESS_DENIED per api-1-infrastructure §4.2. Hoisted from
     * FoundationTestCase (s121, UC-1-032 — pure relocation): the Foundation
     * middleware/two-gate suites and the Companies route-param suite share it.
     */
    protected function assertCompanyAccessDenied(TestResponse $response): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'COMPANY_ACCESS_DENIED');
        $response->assertJsonPath('message', 'You do not have access to this company.');
        $response->assertJsonPath('errors', []);
        $response->assertJsonStructure(['status', 'message', 'error_code', 'errors', 'correlation_id']);
    }
}
