<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breach_incident_companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('breach_incident_id');
            $table->uuid('company_id');
            $table->json('data_types');
            $table->unsignedInteger('estimated_count')->nullable();
            $table->timestamp('added_at');
            $table->uuid('added_by');
            $table->text('added_reason')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('filing_requested_at')->nullable();
            $table->uuid('filing_requested_by')->nullable();
            $table->timestamp('filed_at')->nullable();
            $table->uuid('filed_by')->nullable();
            $table->string('filed_body_hash')->nullable();
            $table->timestamps();

            $table->foreign('breach_incident_id')->references('id')->on('breach_incidents')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('added_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('filing_requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('filed_by')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['breach_incident_id', 'company_id']);
            $table->index(['company_id', 'notified_at']);
        });
    }
};
