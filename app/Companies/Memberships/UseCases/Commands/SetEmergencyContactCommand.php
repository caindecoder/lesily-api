<?php

namespace App\Companies\Memberships\UseCases\Commands;

use Spatie\LaravelData\Data;
use App\Companies\Entities\CompanyUser;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\AuditTrail\Values\FieldChanges;
use App\Companies\Memberships\UseCases\Casts\SriLankanE164Cast;
use App\Companies\Memberships\Enums\EmergencyContactRelationship;

/**
 * An owner's emergency contact for one company (UC-1-029) — the User aggregate's
 * payload, built via ::from() from fields already validated: by
 * UpdateEmergencyContactCommand at the PUT, by CreateCompanyCommand at signup and
 * when a company is added.
 */
class SetEmergencyContactCommand extends Data
{
    public string $name;

    public string $email;

    #[WithCast(SriLankanE164Cast::class)]
    public string $phone;

    public EmergencyContactRelationship $relationship;

    /**
     * Each contact field the set changes, old -> new — every contact field is
     * stored encrypted, so every one is encrypted in the change.
     */
    public function changesFrom(CompanyUser $membership): FieldChanges
    {
        $contact = [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'relationship' => $this->relationship->value,
        ];

        return FieldChanges::between($membership->emergencyContact(), $contact, array_keys($contact));
    }
}
