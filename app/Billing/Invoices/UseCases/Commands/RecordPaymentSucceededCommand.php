<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Foundation\UseCases\Attributes\OpaqueString;

/**
 * Record a verified PayHere payment-success webhook against an invoice
 * (SM-1-04 T2/T4). payHereEventId is the webhook idempotency key — replays
 * are absorbed by the aggregate (no duplicate events, no state change).
 *
 * An internal Spatie\LaravelData command (aggregate-takes-Command, s071) — built
 * via ::from() from server-minted values, so it carries no rules() (conv-plan
 * §2.15.B.2, amendment AQ).
 */
class RecordPaymentSucceededCommand extends Data
{
    #[OpaqueString('the PayHere webhook event id - the idempotency key, not a row of ours')]
    public string $payHereEventId;

    #[OpaqueString('the PayHere transaction id - not a row of ours')]
    public string $payHereTransactionId;

    public int $amountCents;
}
