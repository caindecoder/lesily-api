<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Record a verified PayHere payment-failure webhook against an invoice
 * (SM-1-04 T3 on the first attempt; retry failures with attemptNumber > 1 are
 * recorded without re-firing the transition). payHereEventId is the webhook
 * idempotency key.
 *
 * A Spatie\LaravelData command (aggregate-takes-Command, s071) — constructed via
 * ::validateAndCreate() so inputs are validated at the use-case boundary.
 */
class RecordPaymentFailedCommand extends Data
{
    public string $invoiceId;

    public string $payHereEventId;

    public string $failureCode;

    public ?string $failureMessage = null;

    public int $attemptNumber;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'invoiceId' => ['uuid'],
            'payHereEventId' => ['string', 'max:191'],
            'failureCode' => ['string', 'max:191'],
            'failureMessage' => ['nullable', 'string'],
            'attemptNumber' => ['integer', 'min:1'],
        ];
    }
}
