<?php

namespace App\Consent\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;

/**
 * One consent recording — the user, the company scope, the policy versions being
 * agreed to, and the client IP captured atomically with the record (UC-1-069).
 *
 * A Spatie\LaravelData command (aggregate-takes-Command standing convention,
 * locked company-onboarding #02), built via ::from() by CreateCompanyInteractor
 * and AcceptConsentInteractor from the loaded user and company, config-sourced
 * policy versions and the resolved client IP — each interactor guards the IP
 * before opening its transaction.
 */
class RecordConsentCommand extends Data
{
    public User $user;

    public Company $company;

    /** @var array<string, string> policyType => version */
    public array $policyVersions;

    public string $ip;
}
