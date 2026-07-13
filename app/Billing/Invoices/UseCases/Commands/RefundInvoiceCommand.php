<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Refund an invoice (SM-1-04 T5 credit-on-failed / T6 refund-on-paid).
 * payHereRefundTransactionId is NULL for the T5 credit memo — no money moved.
 * Chunk-1 MVP is full-refund-only; the amount guard lives in the aggregate.
 *
 * A Spatie\LaravelData command (aggregate-takes-Command, s071) — constructed via
 * ::validateAndCreate() so inputs are validated at the use-case boundary.
 */
class RefundInvoiceCommand extends Data
{
    public string $invoiceId;

    public int $refundAmountCents;

    public ?string $payHereRefundTransactionId = null;

    public string $source;

    public string $reason;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'invoiceId' => ['uuid'],
            'refundAmountCents' => ['integer', 'min:0'],
            'payHereRefundTransactionId' => ['nullable', 'string', 'max:191'],
            'source' => ['in:dispute-resolved-in-favor,admin-refund'],
            'reason' => ['string', 'min:1'],
        ];
    }
}
