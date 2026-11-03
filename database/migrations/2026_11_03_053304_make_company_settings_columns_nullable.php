<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * An empty setting follows the company's country (`countries`); a value is the
 * company's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->string('timezone')->nullable()->change();
            $table->char('currency', 3)->nullable()->change();
            $table->string('locale_default', 5)->nullable()->change();
            $table->unsignedTinyInteger('fiscal_year_start_month')->nullable()->change();
            $table->unsignedTinyInteger('working_days_per_week')->nullable()->change();
            $table->decimal('default_work_hours_per_day', 4, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->string('timezone')->nullable(false)->change();
            $table->char('currency', 3)->nullable(false)->change();
            $table->string('locale_default', 5)->nullable(false)->change();
            $table->unsignedTinyInteger('fiscal_year_start_month')->nullable(false)->change();
            $table->unsignedTinyInteger('working_days_per_week')->nullable(false)->change();
            $table->decimal('default_work_hours_per_day', 4, 2)->nullable(false)->change();
        });
    }
};
