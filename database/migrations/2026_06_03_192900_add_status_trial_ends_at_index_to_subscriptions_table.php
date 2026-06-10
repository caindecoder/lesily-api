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
        // The daily `trials:expire` scan (increment #07) filters subscriptions by
        // status + trial_ends_at; this composite index backs that exact predicate.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['status', 'trial_ends_at']);
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
