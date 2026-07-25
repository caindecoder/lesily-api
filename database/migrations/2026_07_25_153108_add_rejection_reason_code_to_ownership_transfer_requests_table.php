<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The machine-readable companion to the verbatim `rejection_reason` prose
     * (ERD-1-05 §3, s151 ruling H1): `identity_failed | target_ineligible |
     * policy_violation | other`, the `RejectionReasonCode` backed enum.
     *
     * Added because UC-1-128's postcondition and its Gherkin both required the
     * code to be PERSISTED while no event payload and no column carried it — the
     * Command validated it and dropped it on the floor, which is the amendment-U
     * dead-validation class. Enforced at the app layer via `Rule::in`, not by a
     * DB constraint, matching `status` / `request_channel` / the three `*_source`
     * columns already on this table.
     *
     * Nullable: every row that predates its rejection has no code, and the three
     * other terminal paths (cancelled / expired / executed) never set one.
     * Forward-only (§24 — no down()).
     */
    public function up(): void
    {
        Schema::table('ownership_transfer_requests', function (Blueprint $table) {
            $table->string('rejection_reason_code')->nullable()->after('rejection_reason');
        });
    }
};
