<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\AuditTrail\Enums\AuditAction;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use App\Foundation\Auth\Events\UserMembershipEmergencyContactSet;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * UC-1-029 — the owner replaces one company's emergency contact:
 * PUT /api/v1/companies/{companyId}/emergency-contact, owner-only by permission.
 */
class UpdateEmergencyContactTest extends MembershipsTestCase
{
    use FreeTierCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeFreeTierCompany();
    }

    #[Test]
    public function when_unauthenticated_then_the_update_is_rejected(): void
    {
        // Given a company whose owner keeps an emergency contact

        // When a guest submits a new contact for it
        $response = $this->updateEmergencyContactAsGuest($this->company->getKey(), self::UPDATED_CONTACT);

        // Then the request is rejected as unauthenticated and the contact is unchanged
        $this->assertUnauthenticated($response);
        $this->assertContactUnchanged($this->company, self::CONTACT, self::READY_MADE_CONTACT_EVENTS);
    }

    #[Test]
    public function when_a_member_updates_another_companys_contact_then_company_access_denied(): void
    {
        // Given the owner of company A and company B, which has its own owner and contact
        $companyB = Company::factory()->create();
        $this->anOwnerWithContact($companyB);

        // When owner A submits a new contact for company B
        $response = $this->updateEmergencyContact($this->owner, $companyB->getKey(), self::UPDATED_CONTACT);

        // Then company access is denied and company B's contact is unchanged
        $this->assertCompanyAccessDenied($response);
        $this->assertContactUnchanged($companyB, self::CONTACT, 0);
    }

    #[Test]
    public function when_the_requested_company_is_unknown_then_resource_not_found(): void
    {
        // Given an owner and a company id nobody holds

        // When the owner submits a contact for that company
        $response = $this->updateEmergencyContact($this->owner, Str::uuid7()->toString(), self::UPDATED_CONTACT);

        // Then the company is not found and the owner's contact is unchanged
        $this->assertResourceNotFound($response);
        $this->assertContactUnchanged($this->company, self::CONTACT, self::READY_MADE_CONTACT_EVENTS);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutThePermission')]
    public function when_a_role_lacks_the_permission_then_the_update_is_refused_and_nothing_is_recorded(string $roleCode): void
    {
        // Given a company whose owner keeps a contact, and a member in another role
        $member = $this->memberWithRole($roleCode);

        // When the member submits a new contact
        $response = $this->updateEmergencyContact($member, $this->company->getKey(), self::UPDATED_CONTACT);

        // Then the update is refused for the missing permission, the contact is unchanged and nothing is audited
        $this->assertRoleInsufficient($response, 'users.set-emergency-contact');
        $this->assertContactUnchanged($this->company, self::CONTACT, self::READY_MADE_CONTACT_EVENTS);
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[Test]
    #[DataProvider('provideInvalidContacts')]
    public function when_an_input_is_invalid_then_it_is_refused_with_its_message_and_the_contact_is_unchanged(array $override, string $field, string $message): void
    {
        // Given a company whose owner keeps an emergency contact

        // When the owner submits a new contact with one invalid field
        $response = $this->updateEmergencyContact($this->owner, $this->company->getKey(), [...self::UPDATED_CONTACT, ...$override]);

        // Then it is refused with that field's message and the stored contact is unchanged
        $this->assertValidationRejected($response, $field, $message);
        $this->assertContactUnchanged($this->company, self::CONTACT, self::READY_MADE_CONTACT_EVENTS);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function provideInvalidContacts(): array
    {
        return [
            'name missing' => [['name' => null], 'name', 'The emergency contact name field is required.'],
            'email missing' => [['email' => null], 'email', 'The emergency contact email field is required.'],
            'phone missing' => [['phone' => null], 'phone', 'The emergency contact phone field is required.'],
            'relationship missing' => [['relationship' => null], 'relationship', 'The emergency contact relationship field is required.'],
            'email not an address' => [['email' => 'not-an-email'], 'email', 'The emergency contact email must be a valid email address.'],
            'phone not a number' => [['phone' => '12345'], 'phone', 'The emergency contact phone must be a valid phone number.'],
            'phone outside Sri Lanka' => [['phone' => '+447911123456'], 'phone', 'The emergency contact phone must be a valid phone number.'],
            'relationship not on the list' => [['relationship' => 'sister'], 'relationship', 'The selected emergency contact relationship is invalid.'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name', 'The emergency contact name field must not be greater than 255 characters.'],
        ];
    }

    #[Test]
    public function when_the_contact_change_cannot_be_audited_then_the_contact_is_unchanged(): void
    {
        // Given production error rendering, a company whose owner keeps a contact, and an audit trail that fails
        config(['app.debug' => false]);
        $this->failTheAuditTrail();

        // When the owner submits a new contact
        $response = $this->updateEmergencyContact($this->owner, $this->company->getKey(), self::UPDATED_CONTACT);

        // Then the update fails whole: the old contact stays and no contact event is recorded
        $response->assertInternalServerError();
        $this->assertContactUnchanged($this->company, self::CONTACT, self::READY_MADE_CONTACT_EVENTS);
        $this->assertSame(self::READY_MADE_CONTACT_EVENTS, LesilyStoredEvent::query()->where('event_class', UserMembershipEmergencyContactSet::class)->count());
    }

    #[Test]
    public function when_the_owner_of_two_companies_updates_one_then_the_other_keeps_its_contact(): void
    {
        // Given one owner of company A and company B, both naming Kamala
        $companyB = Company::factory()->create();
        $this->enrolAsOwner($companyB, $this->owner, self::CONTACT);

        // When the owner sets company B's contact to John
        $response = $this->updateEmergencyContact($this->owner, $companyB->getKey(), self::UPDATED_CONTACT);

        // Then company B has John, recorded for B, and company A still has Kamala
        $response->assertOk();
        $this->assertContactRecorded($companyB, self::UPDATED_CONTACT, 0);
        $this->assertContactUnchanged($this->company, self::CONTACT, self::READY_MADE_CONTACT_EVENTS);
    }

    #[Test]
    public function when_another_member_keeps_a_contact_then_only_the_owners_is_replaced(): void
    {
        // Given a former owner, now an admin, who keeps Kamala, and the owner who keeps Kamala too
        $this->giveContactTo($this->company, $this->admin, self::CONTACT);

        // When the owner sets the company's contact to John
        $response = $this->updateEmergencyContact($this->owner, $this->company->getKey(), self::UPDATED_CONTACT);

        // Then the owner's contact is John and the former owner still keeps Kamala
        $response->assertOk();
        $response->assertExactJson(['data' => self::UPDATED_CONTACT]);
        $this->assertSame(self::UPDATED_CONTACT, $this->storedContact($this->company, $this->owner));
        $this->assertSame(self::CONTACT, $this->storedContact($this->company, $this->admin));
    }

    #[Test]
    public function when_the_same_contact_is_submitted_again_then_no_row_is_written(): void
    {
        // Given a company whose owner keeps an emergency contact

        // When the owner submits the same contact again
        $response = $this->updateEmergencyContact($this->owner, $this->company->getKey(), self::CONTACT);

        // Then the contact event is recorded as before, but nothing changed, so nothing is audited
        $response->assertOk();
        $this->assertContactRecorded($this->company, self::CONTACT, self::READY_MADE_CONTACT_EVENTS);
        $this->assertDatabaseCount('audit_trail', self::READY_MADE_AUDIT_ROWS);
    }

    #[Test]
    public function when_only_the_phone_changes_then_the_row_records_only_the_phone(): void
    {
        // Given a company whose owner keeps an emergency contact

        // When the owner submits the same contact with a new phone, from a device
        $this->fromADevice();
        $response = $this->updateEmergencyContact($this->owner, $this->company->getKey(), [...self::CONTACT, 'phone' => self::UPDATED_CONTACT['phone']]);

        // Then the audit trail records only the phone, its old and new number encrypted
        $response->assertOk();
        $this->assertContactChangeAudited($response, $this->membershipOf($this->company, $this->owner), [
            'phone' => ['from' => self::CONTACT['phone'], 'to' => self::UPDATED_CONTACT['phone']],
        ]);
    }

    #[Test]
    public function when_the_owner_has_no_contact_yet_then_the_update_sets_it(): void
    {
        // Given Contoso's owner, whose contact is cleared
        $this->clearContactOf($this->company, $this->owner);

        // When the owner submits one from a device
        $this->fromADevice();
        $response = $this->updateEmergencyContact($this->owner, $this->company->getKey(), self::UPDATED_CONTACT);

        // Then it is recorded for the company, stored and returned, and audited from nothing to the new values
        $response->assertOk();
        $response->assertExactJson(['data' => self::UPDATED_CONTACT]);
        $this->assertContactRecorded($this->company, self::UPDATED_CONTACT, self::READY_MADE_CONTACT_EVENTS);
        $this->assertContactChangeAudited($response, $this->membershipOf($this->company, $this->owner), $this->changesBetween([], self::UPDATED_CONTACT));
    }

    #[Test]
    public function when_the_name_is_as_long_as_allowed_then_it_is_stored_whole(): void
    {
        // Given a company whose owner keeps an emergency contact
        $longest = [...self::UPDATED_CONTACT, 'name' => str_repeat('a', 255)];

        // When the owner submits a contact whose name is 255 characters long
        $response = $this->updateEmergencyContact($this->owner, $this->company->getKey(), $longest);

        // Then the whole name is stored, its ciphertext longer than a varchar holds
        $response->assertOk();
        $this->assertSame($longest, $this->storedContact($this->company, $this->owner));
    }

    #[Test]
    #[DataProvider('provideLocalPhoneForms')]
    public function when_the_phone_is_written_in_a_local_form_then_it_is_stored_in_international_form(string $phone): void
    {
        // Given a company whose owner keeps an emergency contact

        // When the owner submits a contact whose phone is written the local way
        $response = $this->updateEmergencyContact($this->owner, $this->company->getKey(), [...self::UPDATED_CONTACT, 'phone' => $phone]);

        // Then the phone is recorded, stored and returned as +94712345678
        $response->assertOk();
        $response->assertJsonPath('data.phone', '+94712345678');
        $this->assertContactRecorded($this->company, self::UPDATED_CONTACT, self::READY_MADE_CONTACT_EVENTS);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideLocalPhoneForms(): array
    {
        return [
            'leading zero' => ['0712345678'],
            'leading zero with spaces' => ['071 234 5678'],
            'country code with spaces' => ['+94 71 234 5678'],
        ];
    }

    #[Test]
    public function when_the_contact_is_saved_then_no_stored_copy_holds_plain_text(): void
    {
        // Given a company whose owner keeps an emergency contact

        // When the owner submits a new contact
        $response = $this->updateEmergencyContact($this->owner, $this->company->getKey(), self::UPDATED_CONTACT);

        // Then each of the four stored columns is encrypted, so is each old and new value in the audit row,
        // and the recorded event holds none of the new values
        $response->assertOk();
        $this->assertStoredEncrypted($this->company, $this->owner, self::UPDATED_CONTACT);
        $requestId = $response->headers->get('Correlation-Id');
        $this->assertIsString($requestId);
        $entry = AuditEntry::query()->where('action', AuditAction::EmergencyContactUpdated)->where('request_id', $requestId)->sole();
        $this->assertEncryptedChanges($entry, $this->changesBetween(self::CONTACT, self::UPDATED_CONTACT));
        $this->assertEventHoldsNoPlainText($this->company, self::UPDATED_CONTACT, self::READY_MADE_CONTACT_EVENTS);
    }

    #[Test]
    public function when_the_owner_updates_the_contact_then_it_is_recorded_stored_and_returned(): void
    {
        // Given a company whose owner keeps an emergency contact

        // When the owner submits a new contact from a device
        $this->fromADevice();
        $response = $this->updateEmergencyContact($this->owner, $this->company->getKey(), self::UPDATED_CONTACT);

        // Then the new contact is recorded for the company, stored and returned, and the change is audited
        $response->assertOk();
        $response->assertExactJson(['data' => self::UPDATED_CONTACT]);
        $this->assertContactRecorded($this->company, self::UPDATED_CONTACT, self::READY_MADE_CONTACT_EVENTS);
        $this->assertContactChangeAudited($response, $this->membershipOf($this->company, $this->owner), $this->changesBetween(self::CONTACT, self::UPDATED_CONTACT));
    }

    /**
     * @param  array<string, string>  $old
     * @param  array<string, string>  $new
     * @return array<string, array{from: ?string, to: ?string}>
     */
    protected function changesBetween(array $old, array $new): array
    {
        $changes = [];

        foreach ($new as $field => $to) {
            $changes[$field] = ['from' => $old[$field] ?? null, 'to' => $to];
        }

        return $changes;
    }

    /**
     * The one contact event recorded beyond the $eventsBefore $company held, read
     * past its model, contains none of the $plain values.
     *
     * @param  array<string, string>  $plain
     */
    protected function assertEventHoldsNoPlainText(Company $company, array $plain, int $eventsBefore): void
    {
        $events = DB::table('stored_events')
            ->where('company_id', $company->getKey())
            ->where('event_class', UserMembershipEmergencyContactSet::class);
        $this->assertSame($eventsBefore + 1, $events->count());
        $stored = $events->orderByDesc('id')->value('event_properties');

        $this->assertIsString($stored);
        $this->assertCount(4, $plain);
        foreach ($plain as $value) {
            $this->assertStringNotContainsString($value, $stored);
        }
    }

    /**
     * One contact event was recorded for $company beyond the $eventsBefore it held,
     * by its owner, each contact field encrypted from $expected, and the stored
     * contact reads $expected.
     *
     * @param  array<string, string>  $expected
     */
    protected function assertContactRecorded(Company $company, array $expected, int $eventsBefore): void
    {
        $owner = $company->owner();
        $this->assertInstanceOf(User::class, $owner);
        $events = LesilyStoredEvent::query()
            ->whereForCompany($company->getKey())
            ->where('event_class', UserMembershipEmergencyContactSet::class);
        $this->assertSame($eventsBefore + 1, $events->count());
        $payload = $events->latest('id')->firstOrFail()->event_properties;

        $keys = array_keys($payload);
        sort($keys);
        $this->assertSame(
            ['companyId', 'encryptedEmail', 'encryptedName', 'encryptedPhone', 'encryptedRelationship', 'userId'],
            $keys,
        );
        $this->assertSame([$owner->getKey(), $company->getKey()], [$payload['userId'], $payload['companyId']]);
        $this->assertCount(4, $expected);
        foreach ($expected as $field => $plain) {
            $encrypted = $payload['encrypted'.ucfirst($field)];
            $this->assertIsString($encrypted);
            $this->assertNotSame($plain, $encrypted);
            $this->assertSame($plain, Crypt::decryptString($encrypted));
        }
        $this->assertSame($expected, $this->storedContact($company, $owner));
    }
}
