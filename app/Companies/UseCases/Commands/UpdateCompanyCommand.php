<?php

namespace App\Companies\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * The editable company-details payload (UC-1-033). A per-endpoint whitelist:
 * only name + the four editable settings may change. Immutable columns
 * (br_number, epf_number, country, owner_id, current_billing_plan) and any
 * unknown key are refused with a 422 (MembershipFilters guard precedent),
 * because Spatie Data would otherwise silently drop them. Built at the HTTP
 * boundary via ::validateAndCreate (amendment AQ).
 */
class UpdateCompanyCommand extends Data
{
    /**
     * The body keys this endpoint accepts (camelCase wire, api-conventions §8.1);
     * every other key — including the immutable br_number/epf_number/country — is
     * refused as non-editable.
     *
     * @var list<string>
     */
    private const array EDITABLE_FIELDS = ['name', 'timezone', 'fiscalYearStartMonth', 'workingDaysPerWeek', 'defaultWorkHoursPerDay'];

    public ?string $name = null;

    public ?string $timezone = null;

    public ?int $fiscalYearStartMonth = null;

    public ?int $workingDaysPerWeek = null;

    public ?float $defaultWorkHoursPerDay = null;

    public static function fromRequest(Request $request): static
    {
        $unknownKeys = array_diff(array_keys($request->all()), self::EDITABLE_FIELDS);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages(
                array_fill_keys($unknownKeys, 'This field cannot be updated.')
            );
        }

        return static::validateAndCreate($request->all());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        // sometimes: a partial update validates only the keys it carries. required:
        // a present key may not be null/empty (the settings columns are non-nullable
        // and a name cannot be blanked). The domain bounds mirror the fiscal-month
        // (1-12), week (1-7) and day-hours (0-24) ranges the settings columns model.
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'fiscalYearStartMonth' => ['sometimes', 'required', 'integer', 'between:1,12'],
            'workingDaysPerWeek' => ['sometimes', 'required', 'integer', 'between:1,7'],
            'defaultWorkHoursPerDay' => ['sometimes', 'required', 'numeric', 'between:0,24'],
        ];
    }
}
