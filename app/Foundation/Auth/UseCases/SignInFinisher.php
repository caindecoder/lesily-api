<?php

namespace App\Foundation\Auth\UseCases;

use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Auth\UseCases\Results\SessionIssued;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;

/**
 * The one finish step of every sign-in — password login, 2FA verify and Google. The stamp
 * lives here, not in SessionIssuer, so a future token refresh won't bump it.
 */
class SignInFinisher
{
    public function __construct(
        protected readonly SessionIssuer $sessionIssuer,
        protected readonly AccessLogRecorder $accessLogRecorder,
    ) {}

    public function finish(PendingSession $pendingSession): SessionIssued
    {
        $user = $pendingSession->user;
        $user->last_logged_at = now();
        $user->save();
        $issuedSession = $this->sessionIssuer->issue($user, $pendingSession->deviceName);
        $this->accessLogRecorder->note(AccessEvent::LoginSucceeded, $pendingSession->accessEventReason, $user);

        return new SessionIssued($issuedSession, $user);
    }
}
