<?php

namespace App\Foundation\AccessLog\Enums;

use Illuminate\Http\Response;

enum AccessEvent: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginChallenged = 'login_challenged';
    case LoginFailed = 'login_failed';
    case ReauthFailed = 'reauth_failed';
    case LoggedOut = 'logged_out';
    case OtherSessionsRevoked = 'other_sessions_revoked';
    case SessionRevoked = 'session_revoked';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';

    /** The refusal a response status records when no use case noted an outcome. */
    public static function refusalFor(int $status): ?self
    {
        return match ($status) {
            Response::HTTP_UNAUTHORIZED => self::Unauthenticated,
            Response::HTTP_FORBIDDEN => self::Forbidden,
            Response::HTTP_NOT_FOUND => self::NotFound,
            default => null,
        };
    }
}
