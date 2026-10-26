<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The consent store: one row per recorded ConsentRecorded, never updated or
 * deleted. user_id and company_id are soft references — the consent evidence
 * outlives the rows it names. stored_event_id is the event a row came from, so
 * an event is copied at most once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('stored_event_id')->unique();
            $table->uuid('user_id');
            $table->uuid('company_id');
            $table->string('policy_type');
            $table->string('version');
            $table->string('ip_address', 45);
            $table->timestamp('consented_at');

            $table->index(['user_id', 'company_id', 'policy_type', 'consented_at'], 'consent_records_latest_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
