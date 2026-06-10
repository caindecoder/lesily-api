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
        // SM-1-02 lifecycle state + the trial timer the daily expiry job scans (ERD-1-02).
        Schema::table('companies', function (Blueprint $table) {
            $table->string('status')->default('trial')->after('current_billing_plan');
            $table->timestamp('trial_ends_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
