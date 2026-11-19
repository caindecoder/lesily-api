<?php

namespace App\Companies\Projectors;

use Illuminate\Support\Facades\Crypt;
use App\Companies\Entities\CompanyDataProtectionOfficer;
use App\Companies\Events\CompanyDataProtectionOfficerSet;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use App\Companies\Events\CompanyDataProtectionOfficerRemoved;

/**
 * Mirrors the DPO events into `company_data_protection_officers` (ERD-1-02) — the
 * table's only writer.
 */
class DataProtectionOfficerProjector extends Projector
{
    public function onCompanyDataProtectionOfficerSet(CompanyDataProtectionOfficerSet $event): void
    {
        // The event holds ciphertext; the decrypted values are saved through the model so its
        // encrypted casts apply — a query update skips them.
        CompanyDataProtectionOfficer::query()
            ->firstOrNew(['company_id' => $event->aggregateRootUuid()])
            ->fill([
                'name' => Crypt::decryptString($event->encryptedName),
                'email' => Crypt::decryptString($event->encryptedEmail),
                'phone' => $event->encryptedPhone === null ? null : Crypt::decryptString($event->encryptedPhone),
            ])
            ->save();
    }

    public function onCompanyDataProtectionOfficerRemoved(CompanyDataProtectionOfficerRemoved $event): void
    {
        CompanyDataProtectionOfficer::query()->where('company_id', $event->aggregateRootUuid())->delete();
    }
}
