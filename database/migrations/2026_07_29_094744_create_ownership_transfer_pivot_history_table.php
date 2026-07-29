<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * ownership_transfer_pivot_history — the T4 role-transition record (ERD-1-05
     * §3, ruling P2): two rows per executed transfer, one per affected pivot.
     *
     * It exists because ruling P1 removed the only other record of the
     * transition. With no UserMembershipRoleChanged emission, audit_trail
     * writing metadata => NULL by deliberate s149 ruling, and company_user.role_id
     * mutated in place, the prior role would otherwise be merely INFERABLE by
     * replaying another aggregate's stream and correlating timestamps — thin
     * evidence for a use case whose whole premise is legal defensibility.
     *
     * A derived read model, not a compliance record: the UNIQUE below is the
     * replay key, so a projection rebuild overwrites rather than duplicates.
     * That is the deliberate inverse of audit_trail's append-only posture (R5).
     *
     * No deleted_at (rows are never deleted, nor is the parent transfer row) and
     * no created_by / updated_by (the actor is ownership_transfer_requests
     * .executed_by, one FK hop away; duplicating it would give one fact two
     * places to drift). Forward-only (§24 — no down()).
     */
    public function up(): void
    {
        Schema::create('ownership_transfer_pivot_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('ownership_transfer_requests')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            // RESTRICT is safe on the pivot: memberships soft-delete and are
            // never hard-deleted (ERD-1-01 D5b).
            $table->foreignUuid('membership_id')->constrained('company_user')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('old_role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignUuid('new_role_id')->constrained('roles')->restrictOnDelete();
            $table->timestamp('executed_at');
            $table->timestamps();

            // The replay key AND the natural key: one row per party per transfer.
            $table->unique(['request_id', 'membership_id']);
        });
    }
};
