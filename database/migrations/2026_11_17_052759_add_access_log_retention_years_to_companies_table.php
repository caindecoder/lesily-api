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
        // The longest access-log horizon the company has held, never lowered (ERD-1-02); 7 since every company starts on the trial.
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('access_log_retention_years')->default(7)->after('trial_ends_at');
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
