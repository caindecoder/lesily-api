<?php

namespace App\Companies\Enums;

/**
 * SM-1-02 lifecycle state for a company. Only the states the trial-lifecycle slice
 * reaches are defined; later states (Active, Suspended, …) land with the increments
 * that first write them.
 */
enum CompanyStatus: string
{
    case Trial = 'trial';
    case TrialExpired = 'trial_expired';
}
