<?php

namespace App\Foundation\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * A list or queue request read from the query string: only the keys its
 * QUERY_KEY_WHITELIST names are accepted, and it pages.
 */
abstract class QueryCommand extends Data
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = [];

    public ?int $page = null;

    #[MapInputName('per_page')]
    public ?int $perPage = null;

    public static function fromQuery(Request $request): static
    {
        $unknownKeys = array_diff(array_keys($request->query()), static::QUERY_KEY_WHITELIST);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages(
                array_fill_keys($unknownKeys, 'This filter is not supported.')
            );
        }

        return static::validateAndCreate($request->query() + static::serverFilledKeys($request));
    }

    /**
     * Keys the server fills from the request context, never the client.
     *
     * @return array<string, mixed>
     */
    protected static function serverFilledKeys(Request $request): array
    {
        return [];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        // filled: an empty string skips every non-implicit rule (OQ-1-163) —
        // only an implicit rule can refuse '' at this seam.
        return [
            'page' => ['filled', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['filled', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
