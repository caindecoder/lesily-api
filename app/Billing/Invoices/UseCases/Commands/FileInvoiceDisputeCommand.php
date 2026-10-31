<?php

namespace App\Billing\Invoices\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * File a dispute against an invoice (SM-1-04 modifier — no state transition;
 * sets the has_dispute flag, s133 F8). One open dispute per invoice; resolution
 * events ride the dispute slice (UC-1-054).
 *
 * An internal Spatie\LaravelData command (aggregate-takes-Command, s071) — built
 * via ::from() from server-minted values, so it carries no rules() (conv-plan
 * §2.15.B.2, amendment AQ).
 */
class FileInvoiceDisputeCommand extends Data
{
    public string $reason;

    public User $filedBy;
}
