<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Record a verified PayHere payment-failure webhook against an invoice
 * (SM-1-04 T3 on the first attempt; retry failures with attemptNumber > 1 are
 * recorded without re-firing the transition). payHereEventId is the webhook
 * idempotency key.
 *
 * An internal Spatie\LaravelData command (aggregate-takes-Command, s071) — built
 * via ::from() from server-minted values, so it carries no rules() (conv-plan
 * §2.15.B.2, amendment AQ).
 */
class RecordPaymentFailedCommand extends Data
{
    public string $payHereEventId;

    public string $failureCode;

    public ?string $failureMessage = null;

    public int $attemptNumber;
}
