<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * A LIST read's audit row has no single subject (UC-1-127: one row per queue
 * PAGE), so subject_id becomes nullable. A synthetic uuid was rejected — it
 * would pollute whereSubject()/listForSubject() with rows pointing at
 * nothing. The ['subject_type','subject_id'] composite index survives a
 * nullability change.
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
