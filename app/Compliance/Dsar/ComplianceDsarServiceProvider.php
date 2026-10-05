<?php

namespace App\Compliance\Dsar;

use Illuminate\Support\ServiceProvider;
use App\Compliance\Dsar\UseCases\Sections\DsarSections;

class ComplianceDsarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DsarSections::class, fn (): DsarSections => new DsarSections([]));
    }
}
