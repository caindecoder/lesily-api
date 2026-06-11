<?php

namespace App\Consent\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * One consent recording — the user, the company scope, the policy versions being
 * agreed to, and the client IP captured atomically with the record (UC-1-069).
 *
 * A Spatie\LaravelData command (aggregate-takes-Command standing convention,
 * locked company-onboarding #02) — constructed via ::validateAndCreate().
 */
class RecordConsentCommand extends Data
{
    public string $userId;

    public string $companyId;

    /** @var array<string, string> policyType => version */
    public array $policyVersions;

    public string $ip;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'userId' => ['uuid'],
            'companyId' => ['uuid'],
            'policyVersions' => ['required', 'array'],
            'policyVersions.*' => ['required', 'string', 'max:255'],
            'ip' => ['required', 'string', 'max:255'],
        ];
    }
}
