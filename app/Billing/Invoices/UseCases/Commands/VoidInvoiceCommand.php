<?php

namespace App\Billing\Invoices\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * Void an unpaid invoice (SM-1-04 T7 from Pending / T8 from Failed) — admin
 * correction or pre-payment dispute resolution. No charge was collected.
 *
 * An internal Spatie\LaravelData command (aggregate-takes-Command, s071) — built
 * via ::from() from server-minted values, so it carries no rules() (conv-plan
 * §2.15.B.2, amendment AQ).
 */
class VoidInvoiceCommand extends Data
{
    public User $voidedBy;

    public string $reason;

    public string $source;
}
