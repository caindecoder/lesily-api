<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * UC-1-019 (membership slice #7 Task 3, H3 session 109): the accept path
     * links the pivot back to its originating invitation (invitation_id — NULL
     * for direct owner-signup memberships) and the welcome-wizard flags start
     * false on every pivot-creation path via the schema defaults (the wizard
     * UC later owns flipping them).
     */
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->foreignUuid('invitation_id')
                ->nullable()
                ->constrained('invitations')
                ->restrictOnDelete();
            $table->boolean('onboarding_completed')->default(false);
            $table->boolean('onboarding_skipped')->default(false);
        });
    }

    /**
     * Forward-only (questionnaire §24) — corrections ship as new migrations.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
