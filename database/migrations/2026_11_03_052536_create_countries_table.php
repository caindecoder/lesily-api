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
        Schema::create('countries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('code', 2)->unique();
            $table->string('name');
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
        Schema::dropIfExists('countries');
    }
};
