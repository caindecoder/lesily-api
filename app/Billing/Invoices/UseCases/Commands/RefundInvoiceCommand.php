<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Refund an invoice (SM-1-04 T5 credit-on-failed / T6 refund-on-paid).
 * payHereRefundTransactionId is NULL for the T5 credit memo — no money moved.
 * Chunk-1 MVP is full-refund-only; the amount guard lives in the aggregate.
 *
 * An internal Spatie\LaravelData command (aggregate-takes-Command, s071) — built
 * via ::from() from server-minted values, so it carries no rules() (conv-plan
 * §2.15.B.2, amendment AQ).
 */
class RefundInvoiceCommand extends Data
{
    public int $refundAmountCents;

    public ?string $payHereRefundTransactionId = null;

    public string $source;

    public string $reason;
}
