<?php

namespace App\Compliance\Breach\Enums;

enum BreachStage: string
{
    case Recorded = 'recorded';
    case CompaniesNotified = 'companies_notified';
    case Resolved = 'resolved';
    case FalseAlarm = 'false_alarm';
}
