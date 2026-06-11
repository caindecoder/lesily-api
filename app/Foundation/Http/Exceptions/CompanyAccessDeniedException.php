<?php

namespace App\Foundation\Http\Exceptions;

use Exception;

/**
 * AuthorizeCompany's denial — the company-scope claim was rejected (the auth user
 * is not a member, or no claim was supplied). Maps to error_code
 * COMPANY_ACCESS_DENIED per api-1-infrastructure §4.2. Symmetric with
 * FeatureUnavailableException / RoleInsufficientException.
 */
class CompanyAccessDeniedException extends Exception
{
    public function __construct()
    {
        parent::__construct('You do not have access to this company.');
    }
}
