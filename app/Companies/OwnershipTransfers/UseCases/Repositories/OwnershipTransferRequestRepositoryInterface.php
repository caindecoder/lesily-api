<?php

namespace App\Companies\OwnershipTransfers\UseCases\Repositories;

use App\Companies\OwnershipTransfers\Entities\OwnershipTransferRequest;

/**
 * Read seam for the transfer-request projection (CD-1-05 §3). Task-1 scope:
 * the one-open-per-company invariant lookup; later slice tasks add the timer
 * and queue reads as their increments consume them.
 */
interface OwnershipTransferRequestRepositoryInterface
{
    /**
     * The company's open (requested|verified) transfer, if one exists — at
     * most one per company (ERD-1-05 §5 invariant).
     */
    public function findOpenByCompany(string $companyId): ?OwnershipTransferRequest;
}
