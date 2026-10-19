<?php

namespace App\Foundation\AccessLog\UseCases;

use Throwable;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\AccessLog\Values\AccessOutcome;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\AccessLog\Exceptions\AccessLogWriteFailedException;

/**
 * The access log's one writer (slice-access-log AL1). A use case notes the outcome
 * it decided; RecordAccessOutcome writes the row once the response is known, with
 * the status and route the client was answered with. Bound scoped: the use case
 * and the middleware share one instance per request.
 */
class AccessLogRecorder
{
    private ?AccessOutcome $outcome = null;

    public function note(AccessEvent $event, ?AccessEventReason $reason, ?User $user): void
    {
        $this->outcome = new AccessOutcome($event, $reason, $user?->getKey());
    }

    public function write(Request $request, Response $response): void
    {
        $outcome = $this->outcome;
        $this->outcome = null;

        if ($outcome !== null) {
            $this->insertOrReport($outcome, $request, $response);
        }
    }

    /** A failed write never fails the request; it is reported loudly instead (SL8). */
    private function insertOrReport(AccessOutcome $outcome, Request $request, Response $response): void
    {
        try {
            $this->insert($outcome, $request, $response);
        } catch (Throwable $failure) {
            report(new AccessLogWriteFailedException("Access log write failed: {$outcome->event->value}.", previous: $failure));
        }
    }

    private function insert(AccessOutcome $outcome, Request $request, Response $response): void
    {
        $origin = RequestOrigin::fromRequest($request);
        /** @var Route $route */
        $route = $request->route();
        $seenAt = now();

        AccessLogEntry::query()->create([
            'user_id' => $outcome->userId,
            'event' => $outcome->event,
            'reason' => $outcome->reason,
            'http_method' => $request->method(),
            'route' => $route->uri(),
            'status' => $response->getStatusCode(),
            'error_code' => $this->errorCode($response),
            'ip_address' => $origin->ip,
            'user_agent' => $origin->boundedUserAgent(),
            'request_id' => $origin->boundedRequestId(),
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);
    }

    /** The error envelope's code; a success body, or a body that is not JSON, has none. */
    private function errorCode(Response $response): ?string
    {
        if (! $response instanceof JsonResponse) {
            return null;
        }

        /** @var array{error_code?: string} $body */
        $body = $response->getData(true);

        return $body['error_code'] ?? null;
    }
}
