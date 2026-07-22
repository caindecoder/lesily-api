<?php

namespace App\Companies\OwnershipTransfers\IO\Database\Repositories;

use App\Companies\OwnershipTransfers\Enums\OwnershipTransferStatus;
use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;

class EloquentOwnershipTransferRequestRepository implements OwnershipTransferRequestRepositoryInterface
{
    public function findOpenByCompany(string $companyId): ?OwnershipTransferRequest
    {
        return OwnershipTransferRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [
                OwnershipTransferStatus::Requested,
                OwnershipTransferStatus::Verified,
            ])
            ->first();
    }
}
