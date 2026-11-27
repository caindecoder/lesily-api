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
        $this->app->bind(DsarExportArchive::class, fn (Application $application): DsarExportArchive => new DsarExportArchive(
            $application->make(Filesystem::class),
            sys_get_temp_dir(),
        ));

        $this->app->bind(DsarReportRendererInterface::class, fn (Application $application): ChromiumDsarReportRenderer => new ChromiumDsarReportRenderer(
            $application->make(Filesystem::class),
            sys_get_temp_dir(),
        ));

        $this->app->bind(DsarSections::class, fn (Application $application): DsarSections => new DsarSections([
            $application->make(ProfileSection::class),
            $application->make(MembershipSection::class),
            $application->make(ConsentRecordsSection::class),
            $application->make(AuditTrailSection::class),
            $application->make(SignInsSection::class),
            $application->make(InvitationsSection::class),
            $application->make(OwnershipTransferCasesSection::class),
            $application->make(NotificationsSection::class),
            $application->make(AccessLogSection::class),
        ]));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireDsarDownloads::class, FlagMissedDsarDeadlines::class]);
        }
    }
}
