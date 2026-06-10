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
        // Align to ERD-1-02: current_billing_plan is NULL during Trial — a company
        // has no plan until it subscribes (the projector sets it on CompanySubscribed).
        Schema::table('companies', function (Blueprint $table) {
            $table->string('current_billing_plan', 32)->nullable()->change();
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
