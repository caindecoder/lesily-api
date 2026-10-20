<?php

namespace App\Compliance\Dsar;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use App\Compliance\Dsar\IO\Export\DsarExportArchive;
use App\Compliance\Dsar\IO\Console\ExpireDsarDownloads;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;
use App\Compliance\Dsar\UseCases\Sections\ProfileSection;
use App\Compliance\Dsar\UseCases\Sections\SignInsSection;
use App\Compliance\Dsar\IO\Pdf\ChromiumDsarReportRenderer;
use App\Compliance\Dsar\IO\Console\FlagMissedDsarDeadlines;
use App\Compliance\Dsar\UseCases\Sections\AccessLogSection;
use App\Compliance\Dsar\UseCases\Sections\AuditTrailSection;
use App\Compliance\Dsar\UseCases\Sections\MembershipSection;
use App\Compliance\Dsar\UseCases\Sections\InvitationsSection;
use App\Compliance\Dsar\UseCases\Sections\NotificationsSection;
use App\Compliance\Dsar\UseCases\Sections\ConsentRecordsSection;
use App\Compliance\Dsar\UseCases\Renderers\DsarReportRendererInterface;
use App\Compliance\Dsar\UseCases\Sections\OwnershipTransferCasesSection;

class ComplianceDsarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DsarExportArchive::class, fn (Application $app): DsarExportArchive => new DsarExportArchive(
            $app->make(Filesystem::class),
            sys_get_temp_dir(),
        ));

        $this->app->bind(DsarReportRendererInterface::class, fn (Application $app): ChromiumDsarReportRenderer => new ChromiumDsarReportRenderer(
            $app->make(Filesystem::class),
            sys_get_temp_dir(),
        ));

        $this->app->bind(DsarSections::class, fn (Application $app): DsarSections => new DsarSections([
            $app->make(ProfileSection::class),
            $app->make(MembershipSection::class),
            $app->make(ConsentRecordsSection::class),
            $app->make(AuditTrailSection::class),
            $app->make(SignInsSection::class),
            $app->make(InvitationsSection::class),
            $app->make(OwnershipTransferCasesSection::class),
            $app->make(NotificationsSection::class),
            $app->make(AccessLogSection::class),
        ]));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireDsarDownloads::class, FlagMissedDsarDeadlines::class]);
        }
    }
}
