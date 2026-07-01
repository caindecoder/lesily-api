<?php

namespace App\Foundation\Notifications\Enums;

enum NotificationType: string
{
    case AccountSecurityAlert = 'account_security_alert';
    case GeneralAccountNotice = 'general_account_notice';
    case PasswordChanged = 'password_changed';

    /**
     * Critical notifications (account-security alerts, password-change confirmations)
     * are mandatory: users cannot opt their email out of them. See foundation-brief /
     * questionnaire S16 L474 (security notices reach all tiers and bypass preferences).
     */
    public function isCritical(): bool
    {
        return match ($this) {
            self::AccountSecurityAlert => true,
            self::PasswordChanged => true,
            self::GeneralAccountNotice => false,
        };
    }
}
