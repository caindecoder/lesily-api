<?php

namespace Tests\Concerns;

use ArrayObject;
use App\Models\User;
use Illuminate\Support\Arr;
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
    protected function assertAccessLogged(AccessEvent $event, ?AccessEventReason $reason, ?User $user): AccessLogEntry
    {
        $entry = AccessLogEntry::query()->sole();

        $this->assertSame($event, $entry->event);
        $this->assertSame($reason, $entry->reason);
        $this->assertSame($user?->getKey(), $entry->user_id);

        return $entry;
    }

    protected function assertNoAccessLogged(AccessEvent $event): void
    {
        $this->assertFalse(AccessLogEntry::query()->where('event', $event)->exists(), "No {$event->value} row was expected.");
    }

    protected function assertAnsweredAs(AccessLogEntry $entry, int $status, ?ApiErrorCode $errorCode): void
    {
        $this->assertSame($status, $entry->status);
        $this->assertSame($errorCode?->value, $entry->error_code);
    }

    /**
     * Assert $entry carries the request $response answered: its correlation id, the
     * client's address and user agent, and $endpoint as "<method> <route template>".
     */
    protected function assertLoggedRequest(TestResponse $response, AccessLogEntry $entry, string $endpoint): void
    {
        $correlationId = $response->headers->get('Correlation-Id');
        $this->assertIsString($correlationId);

        $this->assertSame($correlationId, $entry->request_id);
        $this->assertSame('127.0.0.1', $entry->ip_address);
        $this->assertSame('Symfony', $entry->user_agent);
        $this->assertSame($endpoint, "{$entry->http_method} {$entry->route}");
    }

    protected function assertSeenOnceAsAPlatformRow(AccessLogEntry $entry): void
    {
        $this->assertSame(1, $entry->occurrences);
        $this->assertSame(now()->toDateTimeString(), $entry->first_seen_at->toDateTimeString());
        $this->assertSame(now()->toDateTimeString(), $entry->last_seen_at->toDateTimeString());
        $this->assertNull($entry->company_id);
    }

    /**
     * @param  ArrayObject<int, MessageLogged>  $logged
     * @param  array<string, ?string>  $outcome
     */
    protected function assertWriteFailureReported(ArrayObject $logged, array $outcome): void
    {
        $critical = array_values(array_filter($logged->getArrayCopy(), fn (MessageLogged $record): bool => $record->level === 'critical'));

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

    /** @return ArrayObject<int, MessageLogged> */
    protected function collectLogRecords(): ArrayObject
    {
        $logged = new ArrayObject;
        Log::listen(fn (MessageLogged $record) => $logged->append($record));

        return $logged;
    }
}
