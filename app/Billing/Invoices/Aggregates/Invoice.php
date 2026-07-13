<?php

namespace App\Billing\Invoices\Aggregates;

use Carbon\CarbonImmutable;
use App\Foundation\EventStore\AggregateRoot;
use App\Billing\Invoices\Enums\InvoiceStatus;
use App\Billing\Invoices\Events\InvoiceCreated;
use App\Billing\Invoices\UseCases\Commands\IssueInvoiceCommand;
use App\Billing\Invoices\Exceptions\InvoiceAlreadyIssuedException;

class Invoice extends AggregateRoot
{
    protected ?InvoiceStatus $status = null;

    public function issue(IssueInvoiceCommand $command): static
    {
        if ($this->status !== null) {
            throw InvoiceAlreadyIssuedException::withId($this->uuid());
        }

        return $this->forCompany($command->companyId)
            ->recordThat(new InvoiceCreated(
                invoiceId: $this->uuid(),
                subscriptionId: $command->subscriptionId,
                companyId: $command->companyId,
                invoiceNumber: $command->invoiceNumber,
                amountCents: $command->amountCents,
                currency: $command->currency,
                lineItems: $command->lineItems,
                dueDate: $command->dueDate,
                issuedAt: CarbonImmutable::now(),
                source: $command->source,
                recipientName: $command->recipientName,
                recipientEmail: $command->recipientEmail,
                recipientBillingAddress: $command->recipientBillingAddress,
                createdBy: $command->createdBy,
            ));
    }

    protected function applyInvoiceCreated(InvoiceCreated $event): void
    {
        $this->status = InvoiceStatus::Pending;
    }
}
