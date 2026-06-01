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
        Schema::table('stored_events', function (Blueprint $table) {
            $table->char('company_id', 36)->nullable()->after('event_class')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stored_events', function (Blueprint $table) {
            $table->dropColumn('company_id');
        });
    }
};
