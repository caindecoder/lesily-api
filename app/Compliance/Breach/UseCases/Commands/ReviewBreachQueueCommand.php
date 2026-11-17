<?php

namespace App\Compliance\Breach\UseCases\Commands;

use Illuminate\Validation\Rule;
use App\Compliance\Breach\Enums\BreachStage;
use App\Foundation\UseCases\Commands\QueryCommand;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class ReviewBreachQueueCommand extends QueryCommand
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['stage', 'page', 'per_page'];

    public ?BreachStage $stage = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            ...parent::rules($context),
            'stage' => ['filled', 'sometimes', 'string', Rule::enum(BreachStage::class)],
        ];
    }
}
