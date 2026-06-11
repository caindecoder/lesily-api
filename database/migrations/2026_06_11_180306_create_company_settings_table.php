<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

// Per-tenant settings seeded from country defaults at creation (ERD-1-02,
// UC-1-031 step 7). One row per company (UNIQUE company_id).
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->unique()->constrained('companies')->restrictOnDelete();
            $table->string('timezone');
            $table->char('currency', 3);
            $table->string('locale_default', 5);
            $table->unsignedTinyInteger('fiscal_year_start_month');
            $table->unsignedTinyInteger('working_days_per_week');
            $table->decimal('default_work_hours_per_day', 4, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
