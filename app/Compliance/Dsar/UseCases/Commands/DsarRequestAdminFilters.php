<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\BuiltinTypeCast;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DsarRequestAdminFilters extends Data
{
    /**
     * @var list<string>
     */
    private const array QUERY_KEY_WHITELIST = ['status', 'company_id', 'overdue', 'sla_breached', 'page', 'per_page'];

    public ?DsarRequestStatus $status = null;

    #[MapInputName('company_id')]
    #[OpaqueString('a list filter; an unknown value yields an empty list')]
    public ?string $companyId = null;

    #[WithCast(BuiltinTypeCast::class, 'bool')]
    public ?bool $overdue = null;

    #[MapInputName('sla_breached')]
    #[WithCast(BuiltinTypeCast::class, 'bool')]
    public ?bool $slaBreached = null;

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
            'status' => ['filled', 'sometimes', 'string', Rule::enum(DsarRequestStatus::class)],
            'company_id' => ['filled', 'sometimes', 'uuid'],
            'overdue' => ['filled', 'sometimes', Rule::in(['true', 'false'])],
            'sla_breached' => ['filled', 'sometimes', Rule::in(['true', 'false'])],
            'page' => ['filled', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['filled', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
