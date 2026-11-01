<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

class DownloadDsarExportCommand extends Data
{
    #[WithCast(ModelCast::class, DsarRequestRecord::class)]
    public DsarRequestRecord $dsarRequest;

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

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
