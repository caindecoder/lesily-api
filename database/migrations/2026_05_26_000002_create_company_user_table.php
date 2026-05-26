<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            // Note: users.id is bigInt per Laravel default; ERD-1-01 says UUID but full
            // users-table migration to UUID is a chunk-1 follow-up (deferred — tracked).
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('role_id');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles');
            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
