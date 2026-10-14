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
}
