<?php

namespace App\Foundation\Http\Values;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * The request-context slice an admin-surface audit row records (cd-audit-trail
 * Direct-write arm): where the call came from and its correlation id. A plain
 * server-derived value object — NOT a Command (nothing here is user input to
 * validate). Parameter object per the F-d ruling (s137): keeps the interactor
 * signature at three parameters.
 *
 * Foundation-level because every /api/v1/admin/* surface records the same
 * transport facts — it carries no Billing semantics (relocated from
 * App\Billing\Invoices\UseCases\Values s148 D3: the Companies module must not
 * import Billing to reach it — E66 §7 dependency direction).
 */
readonly class AdminRequestContext
{
    public function __construct(
        public ?string $ip,
        public ?string $userAgent,
        public ?string $requestId,
    ) {}

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

    /**
     * The user agent bounded to the audit column's varchar(255). Both this and
     * requestId are CLIENT-CONTROLLED, and MySQL strict mode turns an
     * over-length insert into SQLSTATE 22001 — a 500 rendered AFTER the read
     * already succeeded, with no audit row written (the s137 I-1 ruling).
     *
     * Bounding lives here rather than in each interactor because all three
     * admin-surface writers need it for the identical reason; it was on its
     * third copy before this was folded in, and Tasks 4/5/8 add more.
     */
    public function boundedUserAgent(): ?string
    {
        return $this->bounded($this->userAgent);
    }

    /**
     * The correlation id bounded to the audit column's varchar(255).
     */
    public function boundedRequestId(): ?string
    {
        return $this->bounded($this->requestId);
    }

    private function bounded(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, 255, '');
    }
}
