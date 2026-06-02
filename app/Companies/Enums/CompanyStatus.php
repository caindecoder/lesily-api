<?php

namespace App\Companies\Enums;

/**
 * SM-1-02 lifecycle state for a company. Only the states this slice reaches are
 * defined; later states (TrialExpired, Active, …) land with the increments that
 * first write them.
 */
enum CompanyStatus: string
{
    case Trial = 'trial';
}
