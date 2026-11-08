<?php

namespace App\Compliance\Breach\Enums;

enum BreachAmendmentField: string
{
    case Description = 'description';
    case LikelyConsequences = 'likely_consequences';
    case Measures = 'measures';
    case DateDiscovered = 'date_discovered';
    case Severity = 'severity';
    case CompanyAdded = 'company_added';
    case CompanyRemoved = 'company_removed';
    case DataTypes = 'data_types';
    case EstimatedCount = 'estimated_count';
}
