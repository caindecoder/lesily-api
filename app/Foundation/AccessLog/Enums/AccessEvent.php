<?php

namespace App\Foundation\AccessLog\Enums;

enum AccessEvent: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginChallenged = 'login_challenged';
    case LoginFailed = 'login_failed';
}
