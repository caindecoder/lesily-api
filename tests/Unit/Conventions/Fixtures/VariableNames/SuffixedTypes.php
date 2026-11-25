<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Billing\Invoices\UseCases\Repositories\InvoiceRepositoryInterface;

final class SuffixedTypes
{
    public function read(
        BreachIncidentRecord $breachIncidentRecord,
        InvoiceRepositoryInterface $invoiceRepositoryInterface,
    ): void {
        //
    }
}
