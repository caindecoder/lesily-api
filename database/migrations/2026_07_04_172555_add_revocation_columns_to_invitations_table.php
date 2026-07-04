<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

// UC-1-022 (Task 5): the revoke-specific columns deferred out of create_invitations_table
// (H2 s109 / R6 s110). A revoked invitation keeps its row — revocation is a status
// stamp, never a delete (D5b stable identity; erd-users R-A s111).
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revocation_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only (questionnaire §24) — corrections ship as new migrations.
    }
};
