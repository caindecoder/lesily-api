<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The append-only audit trail (ERD-1-11) — the compliance evidence trail
 * (PDPA Sec. 12, questionnaire §14: audit trail = ALL plans, never deleted).
 * Deliberate deviations from the universal column conventions (ERD-1-11 §3):
 * NO updated_at (rows are written once), NO deleted_at (rows are never
 * deleted — Company hard-delete anonymizes PII in place per R-038), NO
 * created_by/updated_by (actor_user_id carries the actor semantically).
 * stored_event_id is a SOFT FK to stored_events.id (ERD-1-11 §5): bigint,
 * no DB constraint — NULL for rows written outside the event-sourced flow
 * (the UC-1-122 admin-read direct write, slice-plans-billing B3/T6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_trail', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->unsignedBigInteger('stored_event_id')->nullable();
            $table->string('event_class');
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->string('subject_label')->nullable();
            $table->string('action');
            $table->text('description')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->string('actor_role_code')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('severity')->default('info');
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->nullable();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index('company_id');
            $table->index(['company_id', 'recorded_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_user_id');
            $table->index('event_class');
            $table->index('action');
            $table->index('recorded_at');
            $table->index('severity');
            $table->index('request_id');
            $table->index('stored_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_trail');
    }
};
