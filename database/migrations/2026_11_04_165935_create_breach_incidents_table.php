<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breach_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('stage');
            $table->string('severity');
            $table->text('description');
            $table->text('likely_consequences');
            $table->text('measures');
            $table->dateTime('date_discovered');
            $table->timestamp('deadline');
            $table->timestamp('recorded_at');
            $table->uuid('recorded_by');
            $table->timestamp('deadline_missed_at')->nullable();
            $table->timestamp('companies_notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('remediation_steps')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->timestamp('false_alarm_at')->nullable();
            $table->uuid('false_alarm_by')->nullable();
            $table->text('false_alarm_reason')->nullable();
            $table->text('false_alarm_findings')->nullable();
            $table->timestamps();

            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('false_alarm_by')->references('id')->on('users')->restrictOnDelete();

            $table->index(['stage', 'deadline', 'deadline_missed_at']);
        });
    }
};
