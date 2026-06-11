<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

// Owner-mandatory emergency contact lives on the membership pivot (UC-1-029 /
// ERD; per-company — an owner of two companies may have different contacts).
// Nullable: non-owner memberships never carry one.
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->string('emergency_contact_name')->nullable()->after('role_id');
            $table->string('emergency_contact_email')->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_email');
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_phone');
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
