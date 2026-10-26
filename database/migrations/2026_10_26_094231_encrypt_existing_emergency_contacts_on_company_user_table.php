<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Contracts\Encryption\DecryptException;

// Contacts written before the columns were encrypted are encrypted in place, in the
// format the model's `encrypted` cast reads (Crypt::encryptString). No DDL here.
return new class extends Migration
{
    protected const COLUMNS = [
        'emergency_contact_name',
        'emergency_contact_email',
        'emergency_contact_phone',
        'emergency_contact_relationship',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('company_user')
            ->orderBy('id')
            ->each(function (stdClass $row): void {
                DB::table('company_user')->where('id', $row->id)->update($this->encrypted($row));
            });
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally empty — migrations are forward-only in production
     * (questionnaire §24; fix forward with a new migration).
     */
    public function down(): void
    {
        //
    }

    /**
     * @return array<string, string|null>
     */
    protected function encrypted(stdClass $row): array
    {
        $values = [];

        foreach (self::COLUMNS as $column) {
            $value = $row->{$column};
            $values[$column] = $value === null || $this->isEncrypted($value) ? $value : Crypt::encryptString($value);
        }

        return $values;
    }

    /**
     * MySQL runs this outside a transaction, so a re-run after a partial failure or a
     * rollback meets values it already encrypted; those are left as they are.
     */
    protected function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
