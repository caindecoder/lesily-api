<?php

namespace App\Foundation\AuditTrail\UseCases\Commands;

use Closure;
use Carbon\CarbonPeriod;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use Illuminate\Validation\ValidationException;
use App\Foundation\UseCases\Rules\IsoDateRange;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\UseCases\Casts\CarbonPeriodCast;
use App\Foundation\UseCases\Attributes\OpaqueString;
use App\Foundation\AuditTrail\Enums\AuditSubjectType;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class AuditTrailFilters extends Data
{
    public const string LESILY_SUPPORT = 'lesily_support';

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

    public ?int $page = null;

    #[MapInputName('per_page')]
    public ?int $perPage = null;

    public static function fromQuery(Request $request): static
    {
        $unknownKeys = array_diff(array_keys($request->query()), self::QUERY_KEY_WHITELIST);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages(
                array_fill_keys($unknownKeys, 'This filter is not supported.')
            );
        }

        return static::validateAndCreate($request->query());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'within' => ['filled', 'sometimes', 'bail', 'string', new IsoDateRange],
            'actor' => ['filled', 'sometimes', 'bail', 'string', self::memberOrLesilySupportRule()],
            'action' => ['filled', 'sometimes', 'string', Rule::enum(AuditAction::class)],
            'subject_type' => ['filled', 'sometimes', 'string', Rule::enum(AuditSubjectType::class)],
            'page' => ['filled', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['filled', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected static function memberOrLesilySupportRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            /** @var string $value bail + string precede this rule */
            if ($value !== self::LESILY_SUPPORT && ! Str::isUuid($value)) {
                $fail("The {$attribute} filter must be a member's id or lesily_support.");
            }
        };
    }
}
