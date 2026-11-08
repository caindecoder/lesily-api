<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breach_amendments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('breach_incident_id');
            $table->uuid('company_id')->nullable();
            $table->string('field');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('reason');
            $table->timestamp('amended_at');
            $table->uuid('amended_by');
            $table->timestamp('created_at')->nullable();

            $table->foreign('breach_incident_id')->references('id')->on('breach_incidents')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('amended_by')->references('id')->on('users')->restrictOnDelete();

            $table->index(['breach_incident_id', 'amended_at']);
        });
    }
};
