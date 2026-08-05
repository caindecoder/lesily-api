<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The support-assisted ownership-transfer case workflow row (UC-1-140;
     * slice-11R spec §4). Plain-write, interactor-owned — no projector writes
     * this table. status walks open → verified → closed; closed_outcome names
     * which close happened. new_owner_user_id is nullable at open and becomes
     * mandatory at verify (UC-1-113). requested_context is text by documented
     * §2.16 deviation — support prose bounded at 1000 by the Command, the
     * rejection_reason class of column.
     */
    public function up(): void
    {
        Schema::create('ownership_transfer_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('status')->default('open');
            $table->string('closed_outcome')->nullable();
            $table->uuid('new_owner_user_id')->nullable();
            $table->text('requested_context');
            $table->timestamp('verified_at')->nullable();
            $table->uuid('verified_by')->nullable();
            $table->text('verification_notes')->nullable();
            $table->json('verification_artefacts')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('rejection_reason_code')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->uuid('executed_by')->nullable();
            $table->unsignedBigInteger('verification_event_id')->nullable();
            $table->unsignedBigInteger('transfer_event_id')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('new_owner_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('verified_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('executed_by')->references('id')->on('users')->restrictOnDelete();

            $table->index(['company_id', 'status']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ownership_transfer_cases');
    }
};
