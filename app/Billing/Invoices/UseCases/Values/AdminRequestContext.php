<?php

namespace App\Billing\Invoices\UseCases\Values;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * The request-context slice an admin-surface audit row records (cd-audit-trail
 * Direct-write arm): where the call came from and its correlation id. A plain
 * server-derived value object — NOT a Command (nothing here is user input to
 * validate). Parameter object per the F-d ruling (s137): keeps the interactor
 * signature at three parameters.
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
