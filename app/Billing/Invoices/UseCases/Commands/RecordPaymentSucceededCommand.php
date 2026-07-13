<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Record a verified PayHere payment-success webhook against an invoice
 * (SM-1-04 T2/T4). payHereEventId is the webhook idempotency key — replays
 * are absorbed by the aggregate (no duplicate events, no state change).
 *
 * A Spatie\LaravelData command (aggregate-takes-Command, s071) — constructed via
 * ::validateAndCreate() so inputs are validated at the use-case boundary.
 */
class RecordPaymentSucceededCommand extends Data
{
    public string $invoiceId;

    public string $payHereEventId;

    public string $payHereTransactionId;

    public int $amountCents;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'invoiceId' => ['uuid'],
            'payHereEventId' => ['string', 'max:191'],
            'payHereTransactionId' => ['string', 'max:191'],
            'amountCents' => ['integer', 'min:1'],
        ];
    }
}
