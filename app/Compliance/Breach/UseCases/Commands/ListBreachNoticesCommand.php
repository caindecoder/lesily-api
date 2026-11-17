<?php

namespace App\Compliance\Breach\UseCases\Commands;

use App\Foundation\UseCases\Commands\QueryCommand;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class ListBreachNoticesCommand extends QueryCommand
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['page', 'per_page'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            ...parent::rules($context),
        ];
    }
}
