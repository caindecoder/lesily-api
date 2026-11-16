<?php

namespace App\Foundation\AuditTrail\UseCases\Commands;

use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use Illuminate\Validation\ValidationException;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\UseCases\Rules\IsoDateRange;
use Spatie\LaravelData\Attributes\MapInputName;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\UseCases\Casts\CarbonPeriodCast;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Foundation\AuditTrail\UseCases\Rules\MemberOrLesilySupport;

class AuditLogFilters extends Data
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['within', 'user', 'event', 'ip', 'page', 'per_page'];

    #[WithCast(CarbonPeriodCast::class)]
    public ?CarbonPeriod $within = null;

    #[OpaqueString('a member id or lesily_support; an unknown member yields an empty list')]
    public ?string $user = null;

    #[OpaqueString('one access event or one shown security action, values of two enums')]
    public ?string $event = null;

    #[OpaqueString('an IP address, matched exactly')]
    public ?string $ip = null;

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

    public function usesSearch(): bool
    {
        return $this->user !== null || $this->event !== null || $this->ip !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'within' => ['filled', 'sometimes', 'bail', 'string', new IsoDateRange],
            'user' => ['filled', 'sometimes', 'bail', 'string', new MemberOrLesilySupport],
            'event' => ['filled', 'sometimes', 'string', Rule::in([...AccessEvent::cases(), ...AuditAction::SECURITY_ACTIONS])],
            'ip' => ['filled', 'sometimes', 'string', 'ip'],
            'page' => ['filled', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['filled', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
