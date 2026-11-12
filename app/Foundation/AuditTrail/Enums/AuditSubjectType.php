<?php

namespace App\Foundation\AuditTrail\Enums;

enum AuditSubjectType: string
{
    case DsarRequest = 'dsar_request';
    case DsarRequestQueue = 'dsar_request_queue';
    case Invoice = 'invoice';
    case OwnershipTransferCase = 'ownership_transfer_case';
    case OwnershipTransferCaseQueue = 'ownership_transfer_case_queue';
    case BreachIncident = 'breach_incident';
    case BreachIncidentQueue = 'breach_incident_queue';
    case User = 'user';
    case Invitation = 'invitation';
    case Company = 'company';
}
