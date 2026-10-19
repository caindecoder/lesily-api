<?php

namespace App\Foundation\AccessLog\Enums;

enum AccessEvent: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginChallenged = 'login_challenged';
    case LoginFailed = 'login_failed';
    case ReauthFailed = 'reauth_failed';
    case LoggedOut = 'logged_out';
    case OtherSessionsRevoked = 'other_sessions_revoked';
    case SessionRevoked = 'session_revoked';
}
