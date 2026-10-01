<?php

namespace App\Compliance\Dsar;

use Illuminate\Support\ServiceProvider;
use App\Compliance\Dsar\UseCases\Repositories\DsarRequestRepositoryInterface;
use App\Compliance\Dsar\IO\Database\Repositories\EloquentDsarRequestRepository;

class ComplianceDsarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DsarRequestRepositoryInterface::class, EloquentDsarRequestRepository::class);
    }
}
