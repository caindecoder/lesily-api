<?php

namespace App\Foundation\AccessLog\Enums;

enum AccessEvent: string
{
    case LoginSucceeded = 'login_succeeded';
}
