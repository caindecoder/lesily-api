<?php

namespace App\Foundation\AccessLog\Enums;

enum AccessEventReason: string
{
    case WrongPassword = 'wrong_password';
    case UnknownAccount = 'unknown_account';
    case DeviceLimit = 'device_limit';
    case WrongTwoFactorCode = 'wrong_two_factor_code';
    case ChallengeExpired = 'challenge_expired';
    case RecoveryCode = 'recovery_code';
}
