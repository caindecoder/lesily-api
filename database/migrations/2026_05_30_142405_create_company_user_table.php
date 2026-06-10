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
        Schema::create('company_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            // Both FKs RESTRICT: the lifecycle soft-deletes; a hard delete of a user
            // or company with live memberships must fail loudly, never cascade silently.
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->uuid('role_id');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('roles');
            $table->unique(['company_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
