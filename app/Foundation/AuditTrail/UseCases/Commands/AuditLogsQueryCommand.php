<?php

namespace App\Foundation\AuditTrail\UseCases\Commands;

use Carbon\CarbonPeriod;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\UseCases\Rules\IsoDateRange;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\UseCases\Commands\QueryCommand;
use App\Foundation\UseCases\Casts\CarbonPeriodCast;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Foundation\AuditTrail\UseCases\Rules\MemberOrLesilySupport;

/**
 * Which audit-log rows are wanted: a date range and the Pro search keys.
 */
abstract class AuditLogsQueryCommand extends QueryCommand
{
    #[WithCast(CarbonPeriodCast::class)]
    public ?CarbonPeriod $within = null;

    #[OpaqueString('a member id or lesily_support; an unknown member yields an empty list')]
    public ?string $user = null;

    #[OpaqueString('one access event or one shown security action, values of two enums')]
    public ?string $event = null;

    #[OpaqueString('an IP address, matched exactly')]
    public ?string $ip = null;

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
            ...parent::rules($context),
            'within' => ['filled', 'sometimes', 'bail', 'string', new IsoDateRange],
            'user' => ['filled', 'sometimes', 'bail', 'string', new MemberOrLesilySupport],
            'event' => ['filled', 'sometimes', 'string', Rule::in([...AccessEvent::cases(), ...AuditAction::SECURITY_ACTIONS])],
            'ip' => ['filled', 'sometimes', 'string', 'ip'],
        ];
    }
}
