<?php

namespace App\Foundation\Notifications\Enums;

enum NotificationType: string
{
    case AccountSecurityAlert = 'account_security_alert';
    case GeneralAccountNotice = 'general_account_notice';
    case PasswordChanged = 'password_changed';
    case TeamWelcome = 'team_welcome';
    case OwnershipReceived = 'ownership_received';
    case DsarExportReady = 'dsar_export_ready';
    case DsarExportFailed = 'dsar_export_failed';
    case DsarRequestWithdrawn = 'dsar_request_withdrawn';

    /**
     * Critical notifications (account-security alerts, password-change
     * confirmations, ownership-received notices) are mandatory: users cannot
     * opt their email out of them. Security notices per foundation-brief /
     * questionnaire S16 L474; ownership-received is a control-surface change —
     * who owns the company — ruled critical at the UC-1-028 build (s170).
     */
    public function isCritical(): bool
    {
        return match ($this) {
            self::AccountSecurityAlert => true,
            self::PasswordChanged => true,
            self::OwnershipReceived => true,
            self::DsarExportReady => false,
            self::DsarExportFailed => false,
            self::DsarRequestWithdrawn => false,
            self::GeneralAccountNotice => false,
            self::TeamWelcome => false,
        };
    }
}
