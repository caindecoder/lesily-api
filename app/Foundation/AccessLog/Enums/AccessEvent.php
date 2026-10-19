<?php

namespace App\Foundation\AccessLog\Enums;

enum AccessEvent: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
}
