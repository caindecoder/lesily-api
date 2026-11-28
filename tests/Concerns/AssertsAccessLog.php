<?php

namespace Tests\Concerns;

use ArrayObject;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\AccessLog\Exceptions\AccessLogWriteFailedException;

trait AssertsAccessLog
{
    use InducesDatabaseFailures;

    /**
     * Assert the request left exactly one access-log row, for $user (null when no
     * account was resolved), and return it for the column checks that follow.
     */
    protected function assertAccessLogged(AccessEvent $accessEvent, ?AccessEventReason $accessEventReason, ?User $user): AccessLogEntry
    {
        $accessLogEntry = AccessLogEntry::query()->sole();

        $this->assertSame($accessEvent, $accessLogEntry->event);
        $this->assertSame($accessEventReason, $accessLogEntry->reason);
        $this->assertSame($user?->getKey(), $accessLogEntry->user_id);

        return $accessLogEntry;
    }

    /**
     * Assert one $accessEvent row for $user in each of $companies — sharing one time, each with its own uuid v7 id.
     *
     * @param  list<Company>  $companies
     */
    protected function assertAccessLoggedInEachCompany(AccessEvent $accessEvent, User $user, array $companies): void
    {
        $accessLogEntries = AccessLogEntry::query()->get();

        $this->assertCount(count($companies), $accessLogEntries);
        $this->assertEqualsCanonicalizing(array_map(fn (Company $company) => $company->getKey(), $companies), $accessLogEntries->pluck('company_id')->all());
        $this->assertCount(1, $accessLogEntries->map(fn (AccessLogEntry $accessLogEntry): string => $accessLogEntry->first_seen_at->toDateTimeString())->unique());
        $this->assertSame([[$accessEvent, $user->getKey(), true]], $accessLogEntries->map(fn (AccessLogEntry $accessLogEntry): array => [$accessLogEntry->event, $accessLogEntry->user_id, Str::isUuid($accessLogEntry->getKey(), 7)])->unique()->values()->all());
        $this->assertCount(count($companies), $accessLogEntries->pluck('id')->unique());
    }

    protected function assertOneAccessLogInsert(): void
    {
        $inserts = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with($query['query'], 'insert into `access_log`'));

        $this->assertCount(1, $inserts);
    }

    protected function assertNoAccessLogged(AccessEvent $accessEvent): void
    {
        $this->assertFalse(AccessLogEntry::query()->where('event', $accessEvent)->exists(), "No {$accessEvent->value} row was expected.");
    }

    protected function assertAnsweredAs(AccessLogEntry $accessLogEntry, int $status, ?ApiErrorCode $apiErrorCode): void
    {
        $this->assertSame($status, $accessLogEntry->status);
        $this->assertSame($apiErrorCode?->value, $accessLogEntry->error_code);
    }

    /**
     * Assert $accessLogEntry carries the request $response answered: its correlation id, the
     * client's address and user agent, and $endpoint as "<method> <route template>".
     */
    protected function assertLoggedRequest(TestResponse $response, AccessLogEntry $accessLogEntry, string $endpoint): void
    {
        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);

        $this->assertSame($correlationId, $accessLogEntry->request_id);
        $this->assertSame('127.0.0.1', $accessLogEntry->ip_address);
        $this->assertSame('Symfony', $accessLogEntry->user_agent);
        $this->assertSame($endpoint, "{$accessLogEntry->http_method} {$accessLogEntry->route}");
    }

    protected function assertSeenOnceAsAPlatformRow(AccessLogEntry $accessLogEntry): void
    {
        $this->assertSame(1, $accessLogEntry->occurrences);
        $this->assertSame(now()->toDateTimeString(), $accessLogEntry->first_seen_at->toDateTimeString());
        $this->assertSame(now()->toDateTimeString(), $accessLogEntry->last_seen_at->toDateTimeString());
        $this->assertNull($accessLogEntry->company_id);
    }

    /**
     * @param  ArrayObject<int, MessageLogged>  $arrayObject
     * @param  array<string, ?string>  $outcome
     */
    protected function assertWriteFailureReported(ArrayObject $arrayObject, array $outcome): void
    {
        $critical = array_values(array_filter($arrayObject->getArrayCopy(), fn (MessageLogged $messageLogged): bool => $messageLogged->level === 'critical'));

        $this->assertCount(1, $critical);
        $this->assertInstanceOf(AccessLogWriteFailedException::class, $critical[0]->context['exception']);
        $this->assertInstanceOf(QueryException::class, $critical[0]->context['exception']->getPrevious());
        $this->assertSame($outcome, Arr::only($critical[0]->context, array_keys($outcome)));
    }

    protected function failAccessLogWrites(): void
    {
        AccessLogEntry::creating(fn () => throw $this->syntheticQueryException([
            'sqlstate' => 'HY000',
            'code' => 2006,
            'message' => 'MySQL server has gone away',
        ]));
    }

    /** Refuses the insert statement itself — the bulk write of a member's copies fires no model event. */
    protected function failAccessLogInsertStatements(): void
    {
        DB::beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'insert into `access_log`')) {
                throw $this->syntheticQueryException(['sqlstate' => 'HY000', 'code' => 2006, 'message' => 'MySQL server has gone away']);
            }
        });
    }

    /** @return ArrayObject<int, MessageLogged> */
    protected function collectLogRecords(): ArrayObject
    {
        $arrayObject = new ArrayObject;
        Log::listen(fn (MessageLogged $messageLogged) => $arrayObject->append($messageLogged));

        return $arrayObject;
    }
}
