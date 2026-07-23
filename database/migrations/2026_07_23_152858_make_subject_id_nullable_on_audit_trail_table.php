<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * audit_trail.subject_id becomes nullable (UC-1-127 / slice #11 T3 F3).
     *
     * cd-audit-trail models subject_id as a resolvable ENTITY reference
     * (SubjectLabelResolver maps it to a company name, a user name, an invoice
     * number...). A cross-tenant QUERY access-event — "a platform admin opened
     * the transfer queue" — genuinely has no single entity subject, and the CD
     * already anticipates the platform-level row category via
     * platformLevelOnly() (company_id IS NULL). Nulling subject_id is the honest
     * modelling for that category; the rejected alternative was minting a
     * synthetic uuid, which would have polluted whereSubject() /
     * listForSubject() with rows pointing at nothing.
     *
     * Restates the column in full per the Laravel 13 change() rule (all prior
     * attributes must be repeated). The composite ['subject_type','subject_id']
     * index is unaffected by a nullability change. Forward-only (§24 — no down()).
     */
    public function up(): void
    {
        Schema::table('audit_trail', function (Blueprint $table) {
            $table->uuid('subject_id')->nullable()->change();
        });
    }
};
