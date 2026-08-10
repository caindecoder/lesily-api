<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The case records the owner whose handover it is about (UC-1-093
 * precondition): stamped at open from the company's live owner_id, compared
 * by the execute path's stale-owner guard under the row lock. NOT NULL —
 * every case is opened about a specific owner, and the table is empty in
 * every environment at this migration's landing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ownership_transfer_cases', function (Blueprint $table): void {
            $table->uuid('expected_owner_user_id')->after('company_id');

            $table->foreign('expected_owner_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ownership_transfer_cases', function (Blueprint $table): void {
            $table->dropForeign(['expected_owner_user_id']);
            $table->dropColumn('expected_owner_user_id');
        });
    }
};
