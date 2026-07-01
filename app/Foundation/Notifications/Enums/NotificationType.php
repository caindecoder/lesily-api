<?php

namespace App\Foundation\Notifications\Enums;

enum NotificationType: string
{
    case AccountSecurityAlert = 'account_security_alert';
    case GeneralAccountNotice = 'general_account_notice';
    case PasswordChanged = 'password_changed';

    /**
     * Critical notifications (e.g. account-security alerts) are mandatory: users
     * cannot opt their email out of them. See foundation-brief / questionnaire S16.
     */
    public function isCritical(): bool
    {
        return match ($this) {
            self::AccountSecurityAlert => true,
            self::GeneralAccountNotice => false,
            self::PasswordChanged => false,
        };
    }
}
