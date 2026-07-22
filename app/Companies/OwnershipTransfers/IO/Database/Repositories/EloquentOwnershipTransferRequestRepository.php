<?php

namespace App\Companies\OwnershipTransfers\IO\Database\Repositories;

use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

class EloquentOwnershipTransferRequestRepository implements OwnershipTransferRequestRepositoryInterface
{
    public function findOpenByCompany(string $companyId): ?OwnershipTransferRequest
    {
        // The open set derives from the enum's own predicate — one source of
        // truth for "which states are open" (reviewer C1/#2).
        $openStatuses = array_filter(
            OwnershipTransferStatus::cases(),
            fn (OwnershipTransferStatus $status): bool => $status->isOpen(),
        );

        return OwnershipTransferRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $openStatuses)
            ->first();
    }
}
