<?php

namespace App\Foundation\AuditTrail\UseCases\Commands;

use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\WithCast;
use Illuminate\Validation\ValidationException;
use App\Foundation\UseCases\Rules\IsoDateRange;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Foundation\UseCases\Casts\CarbonPeriodCast;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class AuditLogFilters extends Data
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['within', 'page', 'per_page'];

    #[WithCast(CarbonPeriodCast::class)]
    public ?CarbonPeriod $within = null;

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
            'page' => ['filled', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['filled', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
