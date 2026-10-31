<?php

namespace App\Billing\Invoices\UseCases\Commands;

use Closure;
use Carbon\CarbonPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Billing\Invoices\Enums\InvoiceStatusFilter;
use App\Foundation\UseCases\Casts\CarbonPeriodCast;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The invoice-list query input (UC-1-120): whitelisted filter/sort/pagination
 * params per api-conventions §9 + §10. Shape-only validation; unknown query keys
 * are refused (the per-endpoint whitelist rule — §10.1, s134 F-a ruling: a
 * company_id tenant-override attempt 422s like any unknown key) because Data
 * would silently ignore them. MembershipFilters precedent.
 */
class InvoiceFilters extends Data
{
    /**
     * The query keys this endpoint accepts (api-conventions §10.1 whitelist).
     *
     * @var list<string>
     */
    private const array QUERY_KEY_WHITELIST = ['status', 'within', 'min_amount', 'max_amount', 'sort', 'page', 'per_page'];

    /**
     * The sort tokens this endpoint accepts (api-1-invoices §2; `amount` maps to
     * the amount_cents column at the query layer). Default: -issued_at.
     *
     * @var list<string>
     */
    private const array SORTABLE_FIELDS = ['-issued_at', 'issued_at', 'amount', '-amount'];

    public ?InvoiceStatusFilter $status = null;

    #[WithCast(CarbonPeriodCast::class)]
    public ?CarbonPeriod $within = null;

    #[MapInputName('min_amount')]
    public ?int $minAmount = null;

    #[MapInputName('max_amount')]
    public ?int $maxAmount = null;

    #[OpaqueString('a per-endpoint sort token the repository maps to columns')]
    public ?string $sort = null;

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
        // filled: an empty string skips every non-implicit rule (OQ-1-163) —
        // only an implicit rule can refuse '' at this seam. Listed first so
        // its removal stays test-visible and the empty-value message leads.
        return [
            'status' => ['filled', 'sometimes', 'string', Rule::enum(InvoiceStatusFilter::class)],
            // bail: the closure must never run on a non-string (an array
            // within[] would fatal on the string cast before the 422).
            'within' => ['filled', 'sometimes', 'bail', 'string', self::isoDateRangeRule()],
            'min_amount' => ['filled', 'sometimes', 'integer', 'min:0'],
            'max_amount' => ['filled', 'sometimes', 'integer', 'min:0'],
            'sort' => ['filled', 'sometimes', 'string', Rule::in(self::SORTABLE_FIELDS)],
            'page' => ['filled', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['filled', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * `within=from,to` — exactly two comma-separated ISO-8601 (Y-m-d) dates
     * (api-conventions §10.1 date-range convention; UC-1-120 error flow 4c).
     */
    protected static function isoDateRangeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            /** @var string $value bail + string precede this rule */
            $dates = explode(',', $value);

            if (count($dates) !== 2 || ! CarbonImmutable::hasFormat($dates[0], 'Y-m-d') || ! CarbonImmutable::hasFormat($dates[1], 'Y-m-d')) {
                $fail("The {$attribute} filter must be two ISO-8601 (YYYY-MM-DD) dates separated by a comma.");
            }
        };
    }
}
