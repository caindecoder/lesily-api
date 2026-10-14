<?php

namespace App\Foundation\AuditTrail\Values;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * The request-context slice an admin-surface audit row records (cd-audit-trail
 * Direct-write arm): where the call came from and its correlation id. A plain
 * server-derived value object — NOT a Command (nothing here is user input to
 * validate). Parameter object per the F-d ruling (s137): keeps the interactor
 * signature at three parameters. Lives in Foundation so any module's admin
 * interactor can consume it without importing another module (E66 §7 —
 * dependency direction; second consumer arrived with the ownership-transfer
 * case slice).
 */
readonly class RequestOrigin
{
    public function __construct(
        public ?string $ip,
        public ?string $userAgent,
        public ?string $requestId,
    ) {}

    /**
     * Client-controlled header value bounded to the varchar(255) audit
     * columns — MySQL strict mode 22001s an over-length insert, 500ing a
     * valid admin action otherwise (s137 I-1).
     */
    public function boundedUserAgent(): ?string
    {
        return $this->bounded($this->userAgent);
    }

    public function boundedRequestId(): ?string
    {
        return $this->bounded($this->requestId);
    }

    private function bounded(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, 255, '');
    }

    /**
     * Delivery-side translation (E07): lift the transport facts off the
     * request; the correlation id was stamped by AssignCorrelationId.
     */
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
