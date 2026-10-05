<?php

namespace App\Compliance\Dsar;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;
use App\Compliance\Dsar\UseCases\Sections\ProfileSection;
use App\Compliance\Dsar\UseCases\Sections\MembershipSection;

class ComplianceDsarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DsarSections::class, fn (Application $app): DsarSections => new DsarSections([
            $app->make(ProfileSection::class),
            $app->make(MembershipSection::class),
        ]));
    }
}
