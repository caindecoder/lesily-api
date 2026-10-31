<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;

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
    public string $payHereEventId;

    public string $payHereTransactionId;

    public int $amountCents;
}
