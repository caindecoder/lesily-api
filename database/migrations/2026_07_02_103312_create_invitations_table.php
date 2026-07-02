<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

// The invitation read model — one row per UserMembership aggregate (id = the
// aggregate UUID). Invite (UC-1-018) creates a pending row; the accept- and
// revoke-specific columns arrive with their own tasks (forward-only, §24).
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('role_id');
            $table->string('email');
            // sha256 of the single-use token — the plaintext is never stored, it
            // only rides the emailed accept-link (D3/R10, register/reset parity).
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('roles');
            // The invite guard looks up an existing pending invitation by (company, email).
            $table->index(['company_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
