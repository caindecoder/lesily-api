<?php

namespace App\Foundation\Http\Values;

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
}
