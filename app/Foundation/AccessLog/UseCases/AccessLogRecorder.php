<?php

namespace App\Foundation\AccessLog\UseCases;

use Throwable;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Http\JsonResponse;
use App\Companies\Entities\Company;
use Symfony\Component\HttpFoundation\Response;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\AccessLog\Values\AccessOutcome;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Foundation\AccessLog\Entities\AccessLogEntry;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\AccessLog\Exceptions\AccessLogWriteFailedException;

/**
 * The access log's one writer. A use case notes the outcome
 * it decided; RecordAccessOutcome writes the row once the response is known, with
 * the status and route the client was answered with. Bound scoped: the use case
 * and the middleware share one instance per request.
 */
class AccessLogRecorder
{
    /** A refusal row takes repeats for this long after its first one (SL10). */
    protected const FOLD_WINDOW_MINUTES = 5;

    private ?AccessOutcome $outcome = null;

    public function note(AccessEvent $event, ?AccessEventReason $reason, ?User $user): void
    {
        $this->outcome = new AccessOutcome($event, $reason, $user?->getKey());
    }

    public function write(Request $request, Response $response): void
    {
        $outcome = $this->outcome ?? $this->refusal($request, $response);
        $this->outcome = null;

        if ($outcome !== null) {
            $this->insertOrReport($outcome, $request, $response);
        }
    }

    protected function refusal(Request $request, Response $response): ?AccessOutcome
    {
        $event = AccessEvent::refusalFor($response->getStatusCode());
        /** @var ?User $actor */
        $actor = $request->user();
        /** @var ?Company $company set by AuthorizeCompany only once the actor's membership holds */
        $company = $request->attributes->get('company');

        return $event === null ? null : new AccessOutcome($event, null, $actor?->getKey(), $company?->getKey());
    }

    /** A failed write never fails the request; it is reported loudly instead. */
    protected function insertOrReport(AccessOutcome $outcome, Request $request, Response $response): void
    {
        try {
            $this->record($outcome, $request, $response);
        } catch (Throwable $failure) {
            report(new AccessLogWriteFailedException($outcome, $failure));
        }
    }

    protected function record(AccessOutcome $outcome, Request $request, Response $response): void
    {
        $repeated = AccessLogEntry::query()
            ->where('event', $outcome->event)
            ->where('first_seen_at', '>', now()->subMinutes(self::FOLD_WINDOW_MINUTES))
            ->first();

        if ($repeated === null) {
            $this->insert($outcome, $request, $response);

            return;
        }

        $repeated->increment('occurrences', 1, ['last_seen_at' => now()]);
    }

    protected function insert(AccessOutcome $outcome, Request $request, Response $response): void
    {
        $origin = RequestOrigin::fromRequest($request);
        /** @var Route $route */
        $route = $request->route();
        $seenAt = now();

        AccessLogEntry::query()->create([
            'company_id' => $outcome->companyId,
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
    protected function errorCode(Response $response): ?string
    {
        if (! $response instanceof JsonResponse) {
            return null;
        }

        /** @var array{error_code?: string} $body */
        $body = $response->getData(true);

        return $body['error_code'] ?? null;
    }
}
