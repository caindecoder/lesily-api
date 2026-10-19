<?php

namespace App\Foundation\Auth\UseCases;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\AccessLog\Enums\AccessEventReason;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;
use App\Foundation\Auth\Exceptions\DeviceLimitReachedException;

/**
 * Enforces the Pro/Enterprise concurrent-device limit before a session is minted
 * (UC-1-002 alt-5a). The cap is per-account: it applies when the user is a member of at
 * least one company on a device-capped plan, and counts only ACTIVE sessions (the shared
 * ActiveSessions definition — the same set the session list shows, so the list and the
 * cap can never disagree). A capped account at its limit is rejected with
 * DeviceLimitReachedException (403) before SessionIssuer runs, so the existing sessions
 * are untouched. Application policy lives here (E07), called by LogInInteractor +
 * VerifyTwoFactorInteractor — never middleware.
 */
class DeviceLimitGuard
{
    private const MAX_DEVICES = 3;

    public function __construct(private readonly AccessLogRecorder $accessLog) {}

    public function enforce(User $user): void
    {
        if (! $this->onDeviceCappedPlan($user)) {
            return;
        }

        if (ActiveSessions::constrain($user)->count() >= self::MAX_DEVICES) {
            $this->accessLog->note(AccessEvent::LoginFailed, AccessEventReason::DeviceLimit, $user);

            throw new DeviceLimitReachedException;
        }
    }

    protected function onDeviceCappedPlan(User $user): bool
    {
        return $user->companies->contains(
            fn (Company $company): bool => $company->current_billing_plan?->enforcesDeviceLimit() ?? false
        );
    }
}
