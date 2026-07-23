<?php

namespace App\Companies\OwnershipTransfers\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Http\Values\AdminRequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

/**
 * The platform-admin single-transfer review (UC-1-127) — the use case behind
 * PlatformAdminOwnershipTransfersController@show: the deliberately unscoped
 * find, then the ONE append-only audit_trail row recording the read.
 *
 * Same bounded CQS shape as ReviewInvoiceAsAdminInteractor: the audit insert is
 * an orthogonal access-log observation, append-only, and fires ONLY on the
 * success path — the not-found throw happens first, so no row is ever written
 * for a read that did not happen.
 *
 * Unlike the queue's row, this one names a real entity subject and a real
 * tenant, so subject_id and company_id are both populated.
 */
class ReviewOwnershipTransferAsAdminInteractor
{
    public function __construct(private readonly OwnershipTransferRequestRepositoryInterface $transfers) {}

    public function execute(string $requestId, User $admin, AdminRequestContext $context): OwnershipTransferRequest
    {
        $transfer = $this->transfers->findById($requestId);

        if ($transfer === null) {
            throw new ModelNotFoundException;
        }

        $this->recordAdminReview($transfer, $admin, $context);

        return $transfer;
    }

    private function recordAdminReview(OwnershipTransferRequest $transfer, User $admin, AdminRequestContext $context): void
    {
        AuditEntry::query()->create([
            'company_id' => $transfer->company_id,
            'stored_event_id' => null,
            'event_class' => 'AdminOwnershipTransferReviewed',
            'subject_type' => 'ownership_transfer_request',
            'subject_id' => $transfer->getKey(),
            'subject_label' => 'Ownership transfer request',
            'action' => 'admin_reviewed',
            'description' => 'Platform admin reviewed an ownership transfer request.',
            'actor_user_id' => $admin->getKey(),
            'actor_role_code' => 'platform_super_admin',
            'actor_label' => $admin->name,
            'ip_address' => $context->ip,
            'user_agent' => $this->bounded($context->userAgent),
            'request_id' => $this->bounded($context->requestId),
            'metadata' => null,
            'recorded_at' => now(),
        ]);
    }

    private function bounded(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, 255, '');
    }
}
