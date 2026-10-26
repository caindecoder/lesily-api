<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

// The emergency contact is encrypted at rest (questionnaire §20; UC-1-029): the
// `encrypted` cast stores ciphertext longer than a varchar holds.
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->text('emergency_contact_name')->nullable()->change();
            $table->text('emergency_contact_email')->nullable()->change();
            $table->text('emergency_contact_phone')->nullable()->change();
            $table->text('emergency_contact_relationship')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally empty — migrations are forward-only in production
     * (questionnaire §24; fix forward with a new migration).
     */
    public function down(): void
    {
        //
    }
};
