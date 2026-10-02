<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The DSAR request read model (ERD-1-06), written only by
     * DsarRequestProjector. open_request_flag is 1 while the request is
     * in progress and NULL once closed; NULLs never collide, so the composite
     * unique key admits one open request per subject per company (OQ-1-056).
     */
    public function up(): void
    {
        Schema::create('dsar_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('subject_user_id');
            $table->uuid('requested_by');
            $table->string('requested_by_role');
            $table->string('request_type')->default('access');
            $table->string('status')->default('in_progress');
            $table->timestamp('requested_at');
            $table->timestamp('sla_deadline');
            $table->timestamp('sla_breached_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('download_expires_at')->nullable();
            $table->timestamp('download_expired_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->boolean('open_request_flag')->nullable()->storedAs("IF(`status` = 'in_progress', 1, NULL)");
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('subject_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();

            $table->index('status');
            $table->index('sla_deadline');
            $table->index('download_expires_at');
            $table->unique(['company_id', 'subject_user_id', 'open_request_flag'], 'dsar_requests_open_request_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dsar_requests');
    }
};
