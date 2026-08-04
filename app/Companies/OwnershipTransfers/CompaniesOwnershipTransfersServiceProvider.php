<?php

namespace App\Companies\OwnershipTransfers;

use Illuminate\Support\ServiceProvider;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;
use App\Companies\OwnershipTransfers\IO\Database\Repositories\EloquentOwnershipTransferRequestRepository;

/**
 * Ownership-transfer module wiring (CD-1-05 §3). Task-1 scope: the repository
 * binding; the explicit `Projectionist::addProjector()` ordering (OQ-1-010)
 * arrives with the T4 execute task's cross-domain projector set.
 */
class CompaniesOwnershipTransfersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OwnershipTransferRequestRepositoryInterface::class,
            EloquentOwnershipTransferRequestRepository::class,
        );
    }
}
