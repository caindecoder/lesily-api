<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * companies.legal_hold — the §S10b regulatory-freeze flag (questionnaire
     * L327). Slice-#11 D3 ships the BOOLEAN ALONE, deliberately: both of its
     * readers land in this slice (the T2 verify refusal, and the T7 expiry-scan
     * skip clause), while the setter and the reason/date/order_reference
     * companions stay with OQ-1-135's platform-admin slice (UC-1-039/040) — so
     * this is a column with live consumers and no writer yet, not dead schema.
     *
     * Semantics are skip-only, never pause: nothing clears or stashes a timer
     * while a hold stands, so original deadlines apply the moment it lifts.
     *
     * Forward-only (§24 — no down()).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('legal_hold')->default(false)->after('status');
        });
    }
};
