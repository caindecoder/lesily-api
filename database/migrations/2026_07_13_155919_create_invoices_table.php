<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Invoice aggregate read model (ERD-1-04, integer LKR cents per the s129 F1 pin).
 * Trimmed to this slice's writers: next_retry_at (RetryScheduleProjector) and the
 * invoice_payment_attempts table land with the payment slice (E49; subscriptions-
 * migration incremental precedent). No deleted_at — status is the source of truth
 * (ERD-1-04 deviation); rows survive Company hard-delete anonymized (R-038).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('subscription_id');
            $table->string('invoice_number')->unique();
            $table->string('status')->default('pending');
            $table->string('source');
            $table->boolean('has_dispute')->default(false);
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('LKR');
            $table->date('due_date');
            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('payhere_transaction_id')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('last_failure_code')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->string('void_source')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedBigInteger('refund_amount_cents')->nullable();
            $table->string('payhere_refund_transaction_id')->nullable();
            $table->text('refund_reason')->nullable();
            $table->string('refund_source')->nullable();
            $table->string('recipient_name');
            $table->string('recipient_email');
            $table->json('recipient_billing_address')->nullable();
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->restrictOnDelete();
            $table->foreign('voided_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'status']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
