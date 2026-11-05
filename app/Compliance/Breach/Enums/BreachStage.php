<?php

namespace App\Compliance\Breach\Enums;

enum BreachStage: string
{
    case Recorded = 'recorded';
    case CompaniesNotified = 'companies_notified';
}
