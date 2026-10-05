<?php

namespace App\Compliance\Dsar\UseCases\Jobs;

use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class BuildDsarExportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $requestId,
        public string $companyId,
        public string $subjectUserId,
    ) {}

    public function handle(): void {}
}
