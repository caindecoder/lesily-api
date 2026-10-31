<?php

namespace App\Billing\Invoices\Enums;

/**
 * The currency an invoice is issued in (questionnaire §2: LKR by default, multi-currency
 * from day one).
 */
enum Currency: string
{
    case LKR = 'LKR';
    case USD = 'USD';
}
