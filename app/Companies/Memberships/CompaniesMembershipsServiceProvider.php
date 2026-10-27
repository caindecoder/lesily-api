<?php

namespace App\Companies\Memberships;

use Illuminate\Support\ServiceProvider;
use App\Companies\Memberships\IO\Console\ExpireInvitations;
use App\Companies\Memberships\UseCases\Repositories\RoleRepositoryInterface;
use App\Companies\Memberships\IO\Database\Repositories\EloquentRoleRepository;
use App\Companies\Memberships\UseCases\Repositories\InvitationRepositoryInterface;
use App\Companies\Memberships\UseCases\Repositories\CompanyUserRepositoryInterface;
use App\Companies\Memberships\IO\Database\Repositories\EloquentInvitationRepository;
use App\Companies\Memberships\IO\Database\Repositories\EloquentCompanyUserRepository;

class CompaniesMembershipsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CompanyUserRepositoryInterface::class, EloquentCompanyUserRepository::class);
        $this->app->bind(InvitationRepositoryInterface::class, EloquentInvitationRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, EloquentRoleRepository::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireInvitations::class]);
        }
    }
}
