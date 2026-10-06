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
        Schema::table('notifications', function (Blueprint $table) {
            // Null = an account-level notice. No foreign key: the rows outlive a
            // company hard-delete and are anonymised (as stored_events.company_id).
            $table->uuid('company_id')->nullable()->after('notifiable_id')->index();
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
