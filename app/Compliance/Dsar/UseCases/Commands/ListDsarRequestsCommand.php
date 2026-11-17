<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Illuminate\Validation\Rule;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\UseCases\Commands\QueryCommand;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class ListDsarRequestsCommand extends QueryCommand
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['status', 'page', 'per_page'];

    public ?DsarRequestStatus $status = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            ...parent::rules($context),
            'status' => ['filled', 'sometimes', Rule::enum(DsarRequestStatus::class)],
        ];
    }
}
