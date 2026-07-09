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
        Schema::table('users', function (Blueprint $table) {
            // Persisted default-company preference (UC-1-139, slice-companies B2) —
            // a plain column, NOT event-sourced. nullOnDelete: a hard-deleted company
            // must never strand a dangling default (the LeaveDates orphaned-settings
            // scar this FK design dodges); a removed MEMBERSHIP is handled at read
            // time instead (the list derives is_default from live pivots only).
            $table->foreignUuid('default_company_id')
                ->nullable()
                ->after('last_logged_at')
                ->constrained('companies')
                ->nullOnDelete();
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
