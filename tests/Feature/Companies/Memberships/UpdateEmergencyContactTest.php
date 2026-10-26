<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Auth\Events\UserMembershipEmergencyContactSet;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * UC-1-029 — the owner replaces one company's emergency contact:
 * PUT /api/v1/companies/{companyId}/emergency-contact, owner-only by permission.
 */
class UpdateEmergencyContactTest extends MembershipsTestCase
{
    #[Test]
    public function when_unauthenticated_then_the_update_is_rejected(): void
    {
        // Given a company whose owner keeps an emergency contact
        $company = Company::factory()->create();
        $owner = $this->anOwnerWithContact($company);

        // When a guest submits a new contact for it
        $response = $this->updateEmergencyContactAsGuest($company->getKey(), self::UPDATED_CONTACT);

        // Then the request is rejected as unauthenticated and the contact is unchanged
        $this->assertUnauthenticated($response);
        $this->assertContactUnchanged($company, $owner, self::CONTACT);
    }

    #[Test]
    public function when_a_member_updates_another_companys_contact_then_company_access_denied(): void
    {
        // Given the owner of company A and company B, which has its own owner and contact
        $companyA = Company::factory()->create();
        $ownerA = $this->anOwnerWithContact($companyA);
        $companyB = Company::factory()->create();
        $ownerB = $this->anOwnerWithContact($companyB);

        // When owner A submits a new contact for company B
        $response = $this->updateEmergencyContact($ownerA, $companyB->getKey(), self::UPDATED_CONTACT);

        // Then company access is denied and company B's contact is unchanged
        $this->assertCompanyAccessDenied($response);
        $this->assertContactUnchanged($companyB, $ownerB, self::CONTACT);
    }

    #[Test]
    public function when_the_requested_company_is_unknown_then_resource_not_found(): void
    {
        // Given an owner and a company id nobody holds
        $company = Company::factory()->create();
        $owner = $this->anOwnerWithContact($company);

        // When the owner submits a contact for that company
        $response = $this->updateEmergencyContact($owner, Str::uuid7()->toString(), self::UPDATED_CONTACT);

        // Then the company is not found and the owner's contact is unchanged
        $this->assertResourceNotFound($response);
        $this->assertContactUnchanged($company, $owner, self::CONTACT);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutThePermission')]
    public function when_a_role_lacks_the_permission_then_the_update_is_refused_and_nothing_is_recorded(string $roleCode): void
    {
        // Given a company whose owner keeps a contact, and a member in another role
        $company = Company::factory()->create();
        $owner = $this->anOwnerWithContact($company);
        $member = $this->aCompanyMember($company, $roleCode);

        // When the member submits a new contact
        $response = $this->updateEmergencyContact($member, $company->getKey(), self::UPDATED_CONTACT);

        // Then the update is refused for the missing permission and the contact is unchanged
        $this->assertRoleInsufficient($response, 'users.set-emergency-contact');
        $this->assertContactUnchanged($company, $owner, self::CONTACT);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[Test]
    #[DataProvider('provideInvalidContacts')]
    public function when_an_input_is_invalid_then_it_is_refused_with_its_message_and_the_contact_is_unchanged(array $override, string $field, string $message): void
    {
        // Given a company whose owner keeps an emergency contact
        $company = Company::factory()->create();
        $owner = $this->anOwnerWithContact($company);

        // When the owner submits a new contact with one invalid field
        $response = $this->updateEmergencyContact($owner, $company->getKey(), [...self::UPDATED_CONTACT, ...$override]);

        // Then it is refused with that field's message and the stored contact is unchanged
        $this->assertValidationRejected($response, $field, $message);
        $this->assertContactUnchanged($company, $owner, self::CONTACT);
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
    public function when_the_owner_of_two_companies_updates_one_then_the_other_keeps_its_contact(): void
    {
        // Given one owner of company A and company B, both naming Kamala
        $companyA = Company::factory()->create();
        $owner = $this->anOwnerWithContact($companyA);
        $companyB = Company::factory()->create();
        $this->enrolAsOwner($companyB, $owner, self::CONTACT);

        // When the owner sets company B's contact to John
        $response = $this->updateEmergencyContact($owner, $companyB->getKey(), self::UPDATED_CONTACT);

        // Then company B has John, recorded for B, and company A still has Kamala
        $response->assertOk();
        $this->assertContactRecorded($companyB, $owner, self::UPDATED_CONTACT);
        $this->assertContactUnchanged($companyA, $owner, self::CONTACT);
    }

    #[Test]
    public function when_the_owner_has_no_contact_yet_then_the_update_sets_it(): void
    {
        // Given a company whose owner keeps no emergency contact
        $company = Company::factory()->create();
        $owner = $this->aCompanyMember($company, 'owner');

        // When the owner submits one
        $response = $this->updateEmergencyContact($owner, $company->getKey(), self::UPDATED_CONTACT);

        // Then it is set
        $response->assertOk();
        $this->assertSame(self::UPDATED_CONTACT, $this->storedContact($company, $owner));
    }

    #[Test]
    public function when_the_contact_is_saved_then_the_row_holds_no_plain_text(): void
    {
        // Given a company whose owner keeps an emergency contact
        $company = Company::factory()->create();
        $owner = $this->anOwnerWithContact($company);

        // When the owner submits a new contact
        $response = $this->updateEmergencyContact($owner, $company->getKey(), self::UPDATED_CONTACT);

        // Then each of the four stored columns is encrypted
        $response->assertOk();
        $this->assertStoredEncrypted($company, $owner, self::UPDATED_CONTACT);
    }

    #[Test]
    public function when_the_owner_updates_the_contact_then_it_is_recorded_stored_and_returned(): void
    {
        // Given a company whose owner keeps an emergency contact
        $company = Company::factory()->create();
        $owner = $this->anOwnerWithContact($company);

        // When the owner submits a new contact
        $response = $this->updateEmergencyContact($owner, $company->getKey(), self::UPDATED_CONTACT);

        // Then the new contact is recorded for the company, stored and returned
        $response->assertOk();
        $response->assertExactJson(['data' => self::UPDATED_CONTACT]);
        $this->assertContactRecorded($company, $owner, self::UPDATED_CONTACT);
    }

    /**
     * The contact event was recorded once for $company with $expected, and the
     * stored contact reads $expected.
     *
     * @param  array<string, string>  $expected
     */
    protected function assertContactRecorded(Company $company, User $owner, array $expected): void
    {
        $event = LesilyStoredEvent::query()
            ->whereForCompany($company->getKey())
            ->where('event_class', UserMembershipEmergencyContactSet::class)
            ->sole();

        $expectedPayload = ['userId' => $owner->getKey(), 'companyId' => $company->getKey(), ...$expected];
        $payload = $event->event_properties;
        ksort($expectedPayload);
        ksort($payload);

        $this->assertSame($expectedPayload, $payload);
        $this->assertSame($expected, $this->storedContact($company, $owner));
    }
}
