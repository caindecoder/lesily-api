<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * A LIST read's audit row has no single subject (UC-1-127: one row per queue
 * PAGE), so subject_id becomes nullable. A synthetic uuid was rejected — it
 * would leave future subject-scoped audit queries matching rows that point
 * at nothing. The ['subject_type','subject_id'] composite index survives a
 * nullability change. down() is runnable only while no queue read has
 * written a NULL-subject row; the audit trail is append-only, so in practice
 * this migration is one-way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_trail', function (Blueprint $table): void {
            $table->uuid('subject_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_trail', function (Blueprint $table): void {
            $table->uuid('subject_id')->nullable(false)->change();
        });
    }
};
