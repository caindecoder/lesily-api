<?php

namespace App\Compliance\Dsar\UseCases\Jobs;

use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class DeleteDsarExportFileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public string $filePath) {}

    public function handle(): void
    {
        Storage::disk('dsar')->delete($this->filePath);
    }
}
