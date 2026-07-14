<?php

namespace App\Billing\Invoices;

use Illuminate\Support\ServiceProvider;
use App\Billing\Invoices\UseCases\Repositories\InvoiceRepositoryInterface;
use App\Billing\Invoices\IO\Database\Repositories\EloquentInvoiceRepository;

class BillingInvoicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InvoiceRepositoryInterface::class, EloquentInvoiceRepository::class);
    }
}
