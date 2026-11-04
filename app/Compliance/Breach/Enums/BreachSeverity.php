<?php

namespace App\Compliance\Breach\Enums;

enum BreachSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
