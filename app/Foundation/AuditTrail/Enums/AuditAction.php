<?php

namespace App\Foundation\AuditTrail\Enums;

enum AuditAction: string
{
    case DsarRequested = 'dsar_requested';
    case DsarWithdrawn = 'dsar_withdrawn';
    case DsarDownloaded = 'dsar_downloaded';
    case DsarFulfilled = 'dsar_fulfilled';
    case DsarRejected = 'dsar_rejected';
    case DsarDownloadExpired = 'dsar_download_expired';
    case DsarDeadlineMissed = 'dsar_deadline_missed';
    case DsarAdminViewed = 'dsar_admin_viewed';
    case DsarQueueViewed = 'dsar_queue_viewed';
    case CaseOpened = 'case_opened';
    case CaseVerified = 'case_verified';
    case CaseExecuted = 'case_executed';
    case CaseRejected = 'case_rejected';
    case CaseViewed = 'case_viewed';
    case CaseQueueViewed = 'case_queue_viewed';
    case AdminReviewed = 'admin_reviewed';
    case BreachRecorded = 'breach_recorded';
    case BreachAdminViewed = 'breach_admin_viewed';
    case BreachQueueViewed = 'breach_queue_viewed';
    case BreachCompaniesNotified = 'breach_companies_notified';
    case BreachFilingRequested = 'breach_filing_requested';
    case BreachFiledWithRegulator = 'breach_filed_with_regulator';
    case BreachDeadlineMissed = 'breach_deadline_missed';
    case BreachAmended = 'breach_amended';
    case BreachCompanyAdded = 'breach_company_added';
    case BreachResolved = 'breach_resolved';
    case BreachMarkedFalseAlarm = 'breach_marked_false_alarm';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case UserInvited = 'user_invited';
    case BulkInviteSent = 'bulk_invite_sent';
    case InvitationResent = 'invitation_resent';
    case InvitationRevoked = 'invitation_revoked';
    case InvitationAccepted = 'invitation_accepted';
    case RoleChanged = 'role_changed';
    case MemberRemoved = 'member_removed';
}
