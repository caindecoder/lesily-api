<?php

namespace App\Foundation\AuditTrail\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Foundation\AuditTrail\Enums\ExportFormat;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class AuditLogExportFilters extends Data
{
    use FiltersAuditLogs;

    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['within', 'user', 'event', 'ip', 'format'];

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    public ExportFormat $format;

    public static function fromQuery(Request $request): static
    {
        static::refuseUnknownKeys($request, self::QUERY_KEY_WHITELIST);

        $request->query->set('company', $request->company()->getKey());

        return static::validateAndCreate($request->query());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            ...static::filterRules(),
            'format' => ['required', Rule::enum(ExportFormat::class)],
        ];
    }
}
