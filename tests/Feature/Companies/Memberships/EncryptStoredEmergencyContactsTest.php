<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\Migrations\Migration;

/**
 * Contacts stored as plain text before the columns were encrypted are encrypted
 * in place by the data migration (UC-1-029; questionnaire §20).
 */
class EncryptStoredEmergencyContactsTest extends MembershipsTestCase
{
    protected const DATA_MIGRATION = '2026_10_26_094231_encrypt_existing_emergency_contacts_on_company_user_table.php';

    #[Test]
    public function when_a_membership_has_no_contact_then_the_migration_leaves_it_empty(): void
    {
        // Given a member who keeps no emergency contact
        $company = Company::factory()->create();
        $member = $this->aCompanyMember($company);

        // When the data migration runs
        $this->runDataMigration();

        // Then the four contact columns are still empty
        $this->assertSame([null, null, null, null], $this->rawContact($company, $member));
    }

    #[Test]
    public function when_contacts_were_stored_in_plain_text_then_the_migration_encrypts_them(): void
    {
        // Given an owner whose contact was stored as plain text, past the casts
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');
        DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('user_id', $owner->getKey())
            ->update([
                'emergency_contact_name' => self::CONTACT['name'],
                'emergency_contact_email' => self::CONTACT['email'],
                'emergency_contact_phone' => self::CONTACT['phone'],
                'emergency_contact_relationship' => self::CONTACT['relationship'],
            ]);

        // When the data migration runs
        $this->runDataMigration();

        // Then each column holds ciphertext of its old value, which the model reads back
        $this->assertStoredEncrypted($company, $owner, self::CONTACT);
        $this->assertSame(self::CONTACT, $this->storedContact($company, $owner));
    }

    protected function runDataMigration(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/'.self::DATA_MIGRATION);

        $migration->up();
    }

    /**
     * @return array<int, mixed>
     */
    protected function rawContact(Company $company, User $member): array
    {
        $row = DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('user_id', $member->getKey())
            ->sole();

        return [$row->emergency_contact_name, $row->emergency_contact_email, $row->emergency_contact_phone, $row->emergency_contact_relationship];
    }
}
