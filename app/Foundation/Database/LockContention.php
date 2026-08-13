<?php

namespace App\Foundation\Database;

use Illuminate\Database\QueryException;

/**
 * The ONE home for MySQL's lock-contention driver codes (s170 ruling): a
 * lock-wait timeout or deadlock is "the world moved" — interactors translate
 * it to their domain's 409-family exception; every other QueryException must
 * pass through unclassified.
 */
class LockContention
{
    private const int MYSQL_LOCK_WAIT_TIMEOUT = 1205;

    private const int MYSQL_DEADLOCK = 1213;

    public static function caused(QueryException $exception): bool
    {
        return in_array(
            $exception->errorInfo[1] ?? null,
            [self::MYSQL_LOCK_WAIT_TIMEOUT, self::MYSQL_DEADLOCK],
            true,
        );
    }
}
