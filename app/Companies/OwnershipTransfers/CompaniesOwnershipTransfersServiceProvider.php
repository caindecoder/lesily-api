<?php

namespace App\Companies\OwnershipTransfers;

use Illuminate\Support\ServiceProvider;
use App\Companies\OwnershipTransfers\IO\Console\ExpireOwnershipTransfers;
use App\Companies\OwnershipTransfers\UseCases\Repositories\OwnershipTransferRequestRepositoryInterface;
use App\Companies\OwnershipTransfers\IO\Database\Repositories\EloquentOwnershipTransferRequestRepository;

/**
 * Ownership-transfer module wiring (CD-1-05 §3): the repository binding and the
 * module's console command.
 *
 * NO explicit projector registration, deliberately. This docblock used to
 * promise that `Projectionist::addProjector()` ordering (OQ-1-010) would
 * "arrive with the T4 execute task" — T4 has landed and it did not, because
 * ruling P4 superseded the mechanism: `Projectionist` sorts on `getWeight()`
 * before dispatch, so registration order was never the contract it was assumed
 * to be. Ordering now rides explicit `$weight` on the four projectors
 * (10/20/30/40) and auto-discovery is kept.
 *
 * Corrected s161. The promise outlived the decision that replaced it, on the
 * one file a reader opens to check how projectors are registered.
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

    public function boot(): void
    {
        // The module's console commands live under IO/Console, outside Laravel's
        // auto-discovery path — so each module registers its own, exactly as
        // Memberships and Billing/Subscriptions do for their expiry scans.
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireOwnershipTransfers::class]);
        }
    }
}
