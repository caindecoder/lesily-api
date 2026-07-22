<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * ownership_transfer_requests — the SM-1-05 single-table aggregate
     * projection (ERD-1-05 §3): one row per transfer request, stage-specific
     * timestamp + actor + reason columns per transition, forensic old/new-owner
     * pair kept (OQ-1-044), NO deleted_at (terminal states are explicit; rows
     * are audit forensics). Forward-only (§24 — no down()).
     */
    public function up(): void
    {
        Schema::create('ownership_transfer_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('current_owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('target_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('requested');
            $table->string('request_channel');
            $table->text('reason');
            $table->timestamp('requested_at');
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('verification_notes')->nullable();
            $table->json('verification_artefacts')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->foreignUuid('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('old_owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('new_owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->string('rejection_source')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_source')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('expiry_source')->nullable();
            $table->unsignedInteger('expired_after_days')->nullable();
            $table->timestamp('next_state_transition_at')->nullable();
            $table->timestamps();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->index(['company_id', 'status']);
            $table->index('status');
            $table->index('next_state_transition_at');
        });
    }
};
