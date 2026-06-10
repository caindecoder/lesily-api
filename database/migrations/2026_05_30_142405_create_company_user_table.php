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
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->uuid('role_id');
            $table->timestamps();

            // RESTRICT: the lifecycle soft-deletes; a hard delete with live
            // memberships must fail loudly, never cascade them away silently.
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
