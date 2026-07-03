<?php

namespace App\Companies\Memberships;

use Illuminate\Support\ServiceProvider;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;
use App\Companies\Memberships\IO\Database\Repositories\EloquentCompanyUserRepository;

class CompaniesMembershipsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CompanyUserRepositoryInterface::class, EloquentCompanyUserRepository::class);
    }
}
