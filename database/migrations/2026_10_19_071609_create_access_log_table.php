<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The access log (ERD-1-11 "access_log"): one row per access outcome. company_id
 * and user_id are soft references — the log outlives the rows it names. No
 * created_at / updated_at: first_seen_at / last_seen_at carry the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('event');
            $table->string('reason')->nullable();
            $table->string('http_method');
            $table->string('route');
            $table->unsignedSmallInteger('status');
            $table->string('error_code')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_id')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->index(['company_id', 'last_seen_at']);
            $table->index(['user_id', 'last_seen_at']);
            $table->index(['ip_address', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_log');
    }
};
