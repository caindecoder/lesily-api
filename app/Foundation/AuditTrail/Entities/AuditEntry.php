<?php

namespace App\Foundation\AuditTrail\Entities;

use Illuminate\Database\Eloquent\Model;
use Database\Factories\AuditEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One append-only audit_trail row (ERD-1-11) — the human-readable compliance
 * evidence trail. IMMUTABLE BY CONVENTION (slice-plans-billing B3, spec §6):
 * rows are written once and never updated or deleted — no update/delete path
 * exists anywhere in the codebase, the table carries no updated_at
 * (UPDATED_AT = null) and no deleted_at. First production writer is
 * UC-1-122's admin-read direct insert (T7, stored_event_id NULL per
 * ERD-1-11 §5); the AuditTrailReactor machinery stays designed for the
 * UC-1-066 slice (cd-audit-trail).
 */
class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory;

    use HasUuids;

    public const null UPDATED_AT = null;

    /** Eloquent would guess audit_entries; the designed name is singular (F6 s129). */
    protected $table = 'audit_trail';

    protected $fillable = [
        'company_id',
        'stored_event_id',
        'event_class',
        'subject_type',
        'subject_id',
        'subject_label',
        'action',
        'description',
        'actor_user_id',
        'actor_role_code',
        'actor_label',
        'ip_address',
        'user_agent',
        'request_id',
        'metadata',
        'severity',
        'recorded_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'recorded_at' => 'datetime',
    ];

    protected static function newFactory(): AuditEntryFactory
    {
        return AuditEntryFactory::new();
    }
}
