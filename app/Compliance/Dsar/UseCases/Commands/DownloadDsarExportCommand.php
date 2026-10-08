<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Spatie\LaravelData\Data;

class DownloadDsarExportCommand extends Data
{
    public string $requestId;

    public string $companyId;

    /**
     * Both keys are server-sourced (the route id, the company context) and
     * carry no rule (amendment AQ); the method stays because the AQ guard
     * requires one on every controller-referenced command.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [];
    }
}
