<?php

namespace App\Foundation\AccessLog\UseCases;

use Closure;
use Throwable;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Http\JsonResponse;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
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
    /** A refusal row takes repeats for this long after its first one. */
    protected const FOLD_WINDOW_MINUTES = 5;

    private ?AccessOutcome $accessOutcome = null;

    public function note(AccessEvent $accessEvent, ?AccessEventReason $accessEventReason, ?User $user): void
    {
        $this->accessOutcome = new AccessOutcome($accessEvent, $accessEventReason, $user?->getKey());
    }

    /** A noted outcome is never folded — one row per company of its user; a refusal folds into a recent twin. */
    public function write(Request $request, Response $response): void
    {
        $notedAccessOutcome = $this->accessOutcome;
        $this->accessOutcome = null;

        if ($notedAccessOutcome !== null) {
            $this->writeOrReport($notedAccessOutcome, fn () => $this->insertForEachCompany($notedAccessOutcome, $request, $response));

            return;
        }

        $refusalAccessOutcome = $this->refusal($request, $response);

        if ($refusalAccessOutcome !== null) {
            $this->writeOrReport($refusalAccessOutcome, fn () => $this->fold($refusalAccessOutcome, $request, $response));
        }
    }

    protected function refusal(Request $request, Response $response): ?AccessOutcome
    {
        $accessEvent = AccessEvent::refusalFor($response->getStatusCode());

        if ($accessEvent === null) {
            return null;
        }

        /** @var ?User $actor */
        $actor = $request->user();
        /** @var ?Company $company set by AuthorizeCompany only once the actor's membership holds */
        $company = $request->attributes->get('company');

        return new AccessOutcome($accessEvent, null, $actor?->getKey(), $company?->getKey());
    }

    /** A failed write never fails the request; it is reported loudly instead. */
    protected function writeOrReport(AccessOutcome $accessOutcome, Closure $write): void
    {
        try {
            $write();
        } catch (Throwable $throwable) {
            report(new AccessLogWriteFailedException($accessOutcome, $throwable));
        }
    }

    protected function fold(AccessOutcome $accessOutcome, Request $request, Response $response): void
    {
        /** @var Route $route */
        $route = $request->route();
        $accessLogEntry = AccessLogEntry::query()
            ->where('event', $accessOutcome->accessEvent)
            ->where('ip_address', $request->ip())
            ->where('route', $route->uri())
            ->where('http_method', $request->method())
            ->where('user_id', $accessOutcome->userId)
            ->where('company_id', $accessOutcome->companyId)
            ->where('error_code', $this->errorCode($response))
            ->where('first_seen_at', '>', now()->subMinutes(self::FOLD_WINDOW_MINUTES))
            ->first();

        if ($accessLogEntry === null) {
            $this->insert($accessOutcome, $request, $response);
        } else {
            $accessLogEntry->increment('occurrences', 1, ['last_seen_at' => now()]);
        }
    }

    /**
     * A noted outcome reaches each company its user is an active member of, all copies in one
     * statement so none is kept without the others; with no company it is the one platform row.
     */
    protected function insertForEachCompany(AccessOutcome $accessOutcome, Request $request, Response $response): void
    {
        $row = $this->rowFor($accessOutcome, $request, $response);

        AccessLogEntry::query()->insert(array_map(
            fn (?string $companyId): array => [...$row, 'id' => Str::uuid7(), 'company_id' => $companyId],
            $this->companiesOf($accessOutcome->userId) ?: [null],
        ));
    }

    /**
     * @return array<int, string>
     */
    protected function companiesOf(?string $userId): array
    {
        return CompanyUser::query()->where('user_id', $userId)->pluck('company_id')->all();
    }

    protected function insert(AccessOutcome $accessOutcome, Request $request, Response $response): void
    {
        AccessLogEntry::query()->create($this->rowFor($accessOutcome, $request, $response));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rowFor(AccessOutcome $accessOutcome, Request $request, Response $response): array
    {
        $requestOrigin = RequestOrigin::fromRequest($request);
        /** @var Route $route */
        $route = $request->route();
        $seenAt = now();

        return [
            'company_id' => $accessOutcome->companyId,
            'user_id' => $accessOutcome->userId,
            'event' => $accessOutcome->accessEvent->value,
            'reason' => $accessOutcome->accessEventReason?->value,
            'http_method' => $request->method(),
            'route' => $route->uri(),
            'status' => $response->getStatusCode(),
            'error_code' => $this->errorCode($response),
            'ip_address' => $requestOrigin->ip,
            'user_agent' => $requestOrigin->boundedUserAgent(),
            'request_id' => $requestOrigin->boundedRequestId(),
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ];
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
