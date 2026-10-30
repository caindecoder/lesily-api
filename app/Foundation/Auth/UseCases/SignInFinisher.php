<?php

namespace App\Foundation\Auth\UseCases;

use App\Foundation\AccessLog\Enums\AccessEvent;
use App\Foundation\Auth\UseCases\Results\SessionIssued;
use App\Foundation\AccessLog\UseCases\AccessLogRecorder;
use App\Foundation\Auth\Aggregates\User as UserAggregate;

/**
 * The one finish step of every sign-in — password login, 2FA verify and Google. The stamp
 * lives here, not in SessionIssuer, so a future token refresh won't bump it.
 */
class SignInFinisher
{
    public function __construct(
        protected readonly SessionIssuer $sessionIssuer,
        protected readonly AccessLogRecorder $accessLog,
    ) {}

    /** The caller wraps this in its transaction when a Google link is pending — the link and the session commit together. */
    public function finish(PendingSession $pending): SessionIssued
    {
        $user = $pending->user;

        if ($pending->googleSubjectToLink !== null) {
            UserAggregate::retrieve($user->getKey())->linkGoogleOAuth($pending->googleSubjectToLink)->persist();
        }

        $user->last_logged_at = now();
        $user->save();
        $session = $this->sessionIssuer->issue($user, $pending->deviceName);
        $this->accessLog->note(AccessEvent::LoginSucceeded, $pending->factor, $user);

        return new SessionIssued($session, $user);
    }
}
