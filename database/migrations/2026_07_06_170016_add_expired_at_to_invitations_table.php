<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

// T5 (Task 8): the idempotency marker for the daily invitations:expire scan. An
// expired invitation keeps its row — expiry is a status stamp, never a delete
// (D5b stable identity). Display "Expired" stays derived from expires_at; this
// column exists so a stamped row is excluded from the next scan (emit-once).
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->timestamp('expired_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only (questionnaire §24) — corrections ship as new migrations.
    }
};
