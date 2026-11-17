<?php

namespace App\Foundation\AuditTrail\UseCases\Commands;

use Carbon\CarbonPeriod;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Rules\IsoDateRange;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\UseCases\Commands\QueryCommand;
use App\Foundation\UseCases\Casts\CarbonPeriodCast;
use App\Foundation\UseCases\Attributes\OpaqueString;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Foundation\AuditTrail\UseCases\Rules\MemberOrLesilySupport;

class ListAuditTrailCommand extends QueryCommand
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['within', 'actor', 'action', 'subject_type', 'page', 'per_page'];

    #[WithCast(CarbonPeriodCast::class)]
    public ?CarbonPeriod $within = null;

    #[OpaqueString('a member id or lesily_support; an unknown member yields an empty list')]
    public ?string $actor = null;

    public ?AuditAction $action = null;

    #[MapInputName('subject_type')]
    public ?AuditSubjectType $subjectType = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            ...parent::rules($context),
            'within' => ['filled', 'sometimes', 'bail', 'string', new IsoDateRange],
            'actor' => ['filled', 'sometimes', 'bail', 'string', new MemberOrLesilySupport],
            'action' => ['filled', 'sometimes', 'string', Rule::enum(AuditAction::class)],
            'subject_type' => ['filled', 'sometimes', 'string', Rule::enum(AuditSubjectType::class)],
        ];
    }
}
