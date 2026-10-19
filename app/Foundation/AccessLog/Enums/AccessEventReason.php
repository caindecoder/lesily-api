<?php

namespace App\Foundation\AccessLog\Enums;

enum AccessEventReason: string
{
    case WrongPassword = 'wrong_password';
}
