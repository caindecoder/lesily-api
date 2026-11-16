<?php

namespace App\Foundation\AuditTrail\Enums;

enum ExportFormat: string
{
    case Csv = 'csv';
    case Json = 'json';

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Json => 'application/json',
        };
    }
}
