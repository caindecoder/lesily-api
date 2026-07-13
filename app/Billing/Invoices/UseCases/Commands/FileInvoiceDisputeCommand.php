<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * File a dispute against an invoice (SM-1-04 modifier — no state transition;
 * sets the has_dispute flag, s133 F8). One open dispute per invoice; resolution
 * events ride the dispute slice (UC-1-054).
 *
 * A Spatie\LaravelData command (aggregate-takes-Command, s071) — constructed via
 * ::validateAndCreate() so inputs are validated at the use-case boundary.
 */
class FileInvoiceDisputeCommand extends Data
{
    public string $invoiceId;

    public string $reason;

    public string $filedBy;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'invoiceId' => ['uuid'],
            'reason' => ['string', 'min:1'],
            'filedBy' => ['uuid'],
        ];
    }
}
