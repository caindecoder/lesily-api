<?php

namespace App\Companies\OwnershipTransfers\Entities;

use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\OwnershipTransferRequestFactory;
use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;

/**
 * The ownership-transfer read model — one row per OwnershipTransferRequest
 * aggregate (id IS the aggregate UUID). Single-table aggregate per ERD-1-05:
 * the full multi-actor lifecycle lives in stage-specific timestamp + actor +
 * reason columns. NO SoftDeletes (terminal states are explicit; rows are kept
 * for audit forensics — ERD-1-05 deviation note).
 */
class OwnershipTransferRequest extends Model
{
    /** @use HasFactory<OwnershipTransferRequestFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ownership_transfer_requests';

    protected $guarded = [];

    protected $casts = [
        'status' => OwnershipTransferStatus::class,
        'verification_artefacts' => 'array',
        'requested_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
        'executed_at' => 'immutable_datetime',
        'rejected_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'expired_at' => 'immutable_datetime',
        'next_state_transition_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function newFactory(): OwnershipTransferRequestFactory
    {
        return OwnershipTransferRequestFactory::new();
    }
}
