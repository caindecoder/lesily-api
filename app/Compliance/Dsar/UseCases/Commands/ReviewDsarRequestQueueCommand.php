<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\BuiltinTypeCast;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\UseCases\Commands\QueryCommand;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class ReviewDsarRequestQueueCommand extends QueryCommand
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['status', 'company_id', 'overdue', 'sla_breached', 'page', 'per_page'];

    public ?DsarRequestStatus $status = null;

    #[MapInputName('company_id')]
    #[OpaqueString('a list filter; an unknown value yields an empty list')]
    public ?string $companyId = null;

    #[WithCast(BuiltinTypeCast::class, 'bool')]
    public ?bool $overdue = null;

    #[MapInputName('sla_breached')]
    #[WithCast(BuiltinTypeCast::class, 'bool')]
    public ?bool $slaBreached = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            ...parent::rules($context),
            'status' => ['filled', 'sometimes', 'string', Rule::enum(DsarRequestStatus::class)],
            'company_id' => ['filled', 'sometimes', 'uuid'],
            'overdue' => ['filled', 'sometimes', Rule::in(['true', 'false'])],
            'sla_breached' => ['filled', 'sometimes', Rule::in(['true', 'false'])],
        ];
    }
}
