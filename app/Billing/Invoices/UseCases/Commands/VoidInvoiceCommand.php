<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Void an unpaid invoice (SM-1-04 T7 from Pending / T8 from Failed) — admin
 * correction or pre-payment dispute resolution. No charge was collected.
 *
 * A Spatie\LaravelData command (aggregate-takes-Command, s071) — constructed via
 * ::validateAndCreate() so inputs are validated at the use-case boundary.
 */
class VoidInvoiceCommand extends Data
{
    public string $invoiceId;

    public string $voidedBy;

    public string $reason;

    public string $source;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'invoiceId' => ['uuid'],
            'voidedBy' => ['uuid'],
            'reason' => ['string', 'min:1'],
            'source' => ['in:dispute-pre-payment,admin-correction'],
        ];
    }
}
