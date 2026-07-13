<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Immutable invoice line items (ERD-1-04) — created with the parent invoice in
 * the same projection, never updated (corrections = void + new invoice). Only
 * created_at, no updated_at/deleted_at/*_by per the ERD deviation. amount_cents
 * is SIGNED — proration_credit lines carry negative amounts (s129 F1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_line_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('invoice_id');
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->unsignedBigInteger('unit_price_cents');
            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('LKR');
            $table->string('line_type');
            $table->uuid('reference_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_items');
    }
};
