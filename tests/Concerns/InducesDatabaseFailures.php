<?php

namespace Tests\Concerns;

use PDOException;
use Illuminate\Database\QueryException;
use App\Foundation\Http\Responses\ApiErrorCode;

/**
 * Driver-shaped DB failures for inducing at test seams. Consumed by the
 * memberships and platform-admin bases (no shared module base exists below
 * the root — §2.10); lives in an analysed path so Larastan Level 8 covers
 * the array-shape contracts.
 */
trait InducesDatabaseFailures
{
    /**
     * A QueryException carrying $failure's errorInfo on its PDOException
     * previous. The synthetic's PDO code stays 0 (a real PDOException carries
     * the SQLSTATE string there), so Laravel's concurrency detector decides
     * on the MESSAGE alone — which is what lets a row choose whether it is
     * promoted to DeadlockException inside a nested transaction or reaches
     * the raw QueryException arm.
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
     * The lock-contention render matrix (OQ-1-164): both contention codes
     * answer as the 409 conflict family through BOTH arms — detector-matched
     * (promoted to DeadlockException at depth ≥ 2) and detector-avoiding
     * (the raw QueryException arm production hits at top level) — and an
     * unrelated driver failure must pass through unclassified.
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
}
