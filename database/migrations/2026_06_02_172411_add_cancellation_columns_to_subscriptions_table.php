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
        // Trial-end columns the projector writes on SubscriptionTrialEnded (ERD-1-03).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('ended_source')->nullable()->after('trial_ends_at');
            $table->timestamp('cancelled_at')->nullable()->after('ended_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['ended_source', 'cancelled_at']);
        });
    }
};
