<?php

namespace App\Foundation\AuditTrail\Values;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Where an audited call came from: the client's ip and user agent, and
 * the correlation id AssignCorrelationId stamped on the request.
 */
readonly class RequestOrigin
{
    public function __construct(
        public ?string $ip,
        public ?string $userAgent,
        public ?string $requestId,
    ) {}

    /**
     * Bounded to the varchar(255) audit columns: MySQL strict mode refuses
     * an over-length insert, failing a valid action otherwise.
     */
    public function boundedUserAgent(): ?string
    {
        return $this->bounded($this->userAgent);
    }

    public function boundedRequestId(): ?string
    {
        return $this->bounded($this->requestId);
    }

    protected function bounded(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, 255, '');
    }

    public static function fromRequest(Request $request): self
    {
        /** @var ?string $correlationId */
        $correlationId = Context::get('correlation_id');

        return new self(
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $correlationId,
        );
    }
}
