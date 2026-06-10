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
        // BR + EPF are both mandatory, globally unique, immutable post-creation
        // (ERD-1-02, session-069 split). Country is a soft FK by ISO alpha-2 code.
        // owner_id RESTRICT: an owner with a live company must never be silently
        // hard-deleted (closes the OQ-1-105 owner-link).
        Schema::table('companies', function (Blueprint $table) {
            $table->string('br_number')->unique()->after('name');
            $table->string('epf_number')->unique()->after('br_number');
            $table->char('country', 2)->index()->after('epf_number');
            $table->foreignUuid('owner_id')->after('country')->constrained('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['br_number', 'epf_number', 'country']);
        });
    }
};
