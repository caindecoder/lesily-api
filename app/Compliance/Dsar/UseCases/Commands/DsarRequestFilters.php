<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DsarRequestFilters extends Data
{
    /**
     * @var list<string>
     */
    private const array QUERY_KEY_WHITELIST = ['status', 'page', 'per_page'];

    public ?string $status = null;

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
            'status' => ['filled', 'sometimes', Rule::enum(DsarRequestStatus::class)],
            'page' => ['filled', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['filled', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
