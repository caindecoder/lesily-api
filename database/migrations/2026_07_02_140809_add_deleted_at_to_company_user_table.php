<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            // Removal (UC-1-024) soft-deletes the membership — the row anchors
            // retained employment records and a re-invite undeletes it in place,
            // keeping the membership UUID stable (D5/D5b; ERD-1-01 R2). Indexed:
            // the auth gates filter on it for every tenant-scoped request (R7).
            $table->softDeletes()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only: migrations are never rolled back (questionnaire §24 + LeaveDates).
    }
};
