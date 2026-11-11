<?php

namespace App\Foundation\AuditTrail\Entities;

use Illuminate\Database\Eloquent\Model;
use Database\Factories\AuditEntryFactory;
use App\Foundation\AuditTrail\Enums\AuditAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One append-only audit_trail row (ERD-1-11) — the human-readable compliance
 * evidence trail, written by the interactor that performs the action. Rows are
 * written once and never updated or deleted: no update/delete path exists, the
 * table carries no updated_at (UPDATED_AT = null) and no deleted_at.
 */
class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory;

    use HasUuids;

    public const null UPDATED_AT = null;

    /** The role code every platform-staff writer records. */
    public const string STAFF_ROLE_CODE = 'platform_super_admin';

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
        'action' => AuditAction::class,
        'subject_type' => AuditSubjectType::class,
        'metadata' => 'array',
        'recorded_at' => 'datetime',
    ];

    protected static function newFactory(): AuditEntryFactory
    {
        return AuditEntryFactory::new();
    }
}
