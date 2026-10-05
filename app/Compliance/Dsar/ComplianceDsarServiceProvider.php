<?php

namespace App\Compliance\Dsar;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use App\Compliance\Dsar\IO\Export\DsarExportArchive;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;
use App\Compliance\Dsar\UseCases\Sections\ProfileSection;
use App\Compliance\Dsar\UseCases\Sections\AuditTrailSection;
use App\Compliance\Dsar\UseCases\Sections\MembershipSection;
use App\Compliance\Dsar\UseCases\Sections\ConsentRecordsSection;

class ComplianceDsarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DsarExportArchive::class, fn (Application $app): DsarExportArchive => new DsarExportArchive(
            $app->make(Filesystem::class),
            sys_get_temp_dir(),
        ));

        $this->app->bind(DsarSections::class, fn (Application $app): DsarSections => new DsarSections([
            $app->make(ProfileSection::class),
            $app->make(MembershipSection::class),
            $app->make(ConsentRecordsSection::class),
            $app->make(AuditTrailSection::class),
        ]));
    }
}
