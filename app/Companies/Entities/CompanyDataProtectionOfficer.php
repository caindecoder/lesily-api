<?php

namespace App\Companies\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\CompanyDataProtectionOfficerFactory;

/**
 * A company's data protection officer (ERD-1-02 `company_data_protection_officers`;
 * UC-1-146) — written only by DataProtectionOfficerProjector.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string $email
 * @property ?string $phone
 */
class CompanyDataProtectionOfficer extends Model
{
    /** @use HasFactory<CompanyDataProtectionOfficerFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['company_id', 'name', 'email', 'phone'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
        ];
    }

    /**
     * @return array{name: string, email: string, phone: ?string}
     */
    public function contact(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'phone' => $this->phone];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function newFactory(): CompanyDataProtectionOfficerFactory
    {
        return CompanyDataProtectionOfficerFactory::new();
    }
}
