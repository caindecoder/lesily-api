<?php

namespace App\Companies\Entities;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Companies\Enums\OwnershipTransferCaseStatus;
use App\Companies\Enums\OwnershipTransferCaseOutcome;

/**
 * A support-assisted ownership-transfer case (UC-1-140; slice-11R spec §4).
 * Plain-write workflow state, interactor-owned — no projector writes this
 * table and no domain event exists until verify (UC-1-113) records one.
 *
 * @property string $id
 * @property string $company_id
 * @property OwnershipTransferCaseStatus $status
 * @property ?OwnershipTransferCaseOutcome $closed_outcome
 * @property ?string $new_owner_user_id
 * @property string $requested_context
 * @property ?CarbonImmutable $verified_at
 * @property ?string $verified_by
 * @property ?string $verification_notes
 * @property ?array<int, array<string, string>> $verification_artefacts
 * @property ?string $rejection_reason
 * @property ?string $rejection_reason_code
 * @property ?CarbonImmutable $executed_at
 * @property ?string $executed_by
 * @property ?int $verification_event_id
 * @property ?int $transfer_event_id
 * @property Carbon $created_at
 * @property ?Carbon $updated_at
 */
class OwnershipTransferCase extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'status',
        'closed_outcome',
        'new_owner_user_id',
        'requested_context',
        'verified_at',
        'verified_by',
        'verification_notes',
        'verification_artefacts',
        'rejection_reason',
        'rejection_reason_code',
        'executed_at',
        'executed_by',
        'verification_event_id',
        'transfer_event_id',
    ];

    protected $casts = [
        'status' => OwnershipTransferCaseStatus::class,
        'closed_outcome' => OwnershipTransferCaseOutcome::class,
        'verification_artefacts' => 'array',
        'verified_at' => 'immutable_datetime',
        'executed_at' => 'immutable_datetime',
    ];
}
