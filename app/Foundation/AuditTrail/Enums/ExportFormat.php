<?php

namespace App\Foundation\AuditTrail\Enums;

enum ExportFormat: string
{
    case Csv = 'csv';
    case Json = 'json';
}
