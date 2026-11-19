<?php

namespace Database\Factories;

use App\Companies\Entities\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Companies\Entities\CompanyDataProtectionOfficer;

/**
 * @extends Factory<CompanyDataProtectionOfficer>
 */
class CompanyDataProtectionOfficerFactory extends Factory
{
    protected $model = CompanyDataProtectionOfficer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Nimal Silva',
            'email' => 'dpo@contoso.lk',
            'phone' => '+94771234567',
        ];
    }

    public function withoutPhone(): static
    {
        return $this->state(['phone' => null]);
    }
}
