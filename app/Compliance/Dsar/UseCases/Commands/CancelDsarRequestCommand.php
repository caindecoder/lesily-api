<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Spatie\LaravelData\Data;

class CancelDsarRequestCommand extends Data
{
    public string $requestId;

    public string $companyId;

    public string $cancelledByUserId;

    /**
     * Every key is server-sourced (the route id, the company context, the
     * actor) and carries no rule (amendment AQ); the method stays because the
     * AQ guard requires one on every controller-referenced command.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [];
    }
}
