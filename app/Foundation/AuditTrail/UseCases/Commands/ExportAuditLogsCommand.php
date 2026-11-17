<?php

namespace App\Foundation\AuditTrail\UseCases\Commands;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Foundation\AuditTrail\Enums\ExportFormat;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class ExportAuditLogsCommand extends AuditLogsQueryCommand
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['within', 'user', 'event', 'ip', 'format'];

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    public ExportFormat $format;

    /**
     * @return array<string, mixed>
     */
    protected static function serverFilledKeys(Request $request): array
    {
        return ['company' => $request->company()->getKey()];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            ...parent::rules($context),
            'format' => ['required', Rule::enum(ExportFormat::class)],
        ];
    }
}
