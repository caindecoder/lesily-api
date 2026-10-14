<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DsarRequestAdminFilters extends Data
{
    /**
     * @var list<string>
     */
    private const array QUERY_KEY_WHITELIST = ['status', 'company_id', 'overdue', 'sla_breached', 'page', 'per_page'];

    public ?string $status = null;

    public ?string $company_id = null;

    public ?string $overdue = null;

    public ?string $sla_breached = null;

    public ?int $page = null;

    public ?int $per_page = null;

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

    public function overdueOnly(): bool
    {
        return $this->overdue === 'true';
    }

    public function breachedOnly(): bool
    {
        return $this->sla_breached === 'true';
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
