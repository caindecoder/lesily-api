<?php

namespace App\Companies\UseCases\Audit;

use App\Companies\Entities\Company;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Values\FieldChanges;

/**
 * One kind of change a company update made: its details, its own settings, or one
 * setting put back on the country default.
 */
readonly class CompanyChange
{
    public function __construct(
        public Company $company,
        public AuditAction $action,
        public FieldChanges $changes,
    ) {}
}
