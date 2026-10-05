<?php

namespace App\Compliance\Dsar\UseCases\Sections;

use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;

class MembershipSection implements DsarSectionProvider
{
    public function key(): string
    {
        return 'membership';
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(string $subjectUserId, string $companyId): array
    {
        $company = Company::query()->findOrFail($companyId);
        $membership = CompanyUser::query()
            ->with('role')
            ->where('company_id', $companyId)
            ->where('user_id', $subjectUserId)
            ->firstOrFail();

        return [
            'company' => ['id' => $company->getKey(), 'name' => $company->name],
            'role' => $membership->role?->code,
            'joined_at' => $membership->created_at?->toISOString(),
            'emergency_contact' => [
                'name' => $membership->emergency_contact_name,
                'email' => $membership->emergency_contact_email,
                'phone' => $membership->emergency_contact_phone,
                'relationship' => $membership->emergency_contact_relationship,
            ],
        ];
    }
}
