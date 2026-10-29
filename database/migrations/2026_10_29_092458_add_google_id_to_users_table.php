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
            $table->string('google_id')->nullable()->unique('users_google_id_unique')->after('password');
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
