<?php

namespace Tests\Feature\Compliance\Dsar;

use App\Models\User;
use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;

class ExportDsarSectionsTest extends DsarTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    #[Test]
    public function when_the_export_is_built_then_the_membership_section_holds_their_role_and_emergency_contact(): void
    {
        // Given an HR officer who named an emergency contact
        $member = User::factory()->create();
        $this->company->users()->attach($member, [
            'role_id' => $this->seededRole('hr_officer')->getKey(),
            'emergency_contact_name' => 'Nimali Perera',
            'emergency_contact_email' => 'nimali@example.lk',
            'emergency_contact_phone' => '+94771234567',
            'emergency_contact_relationship' => 'Sister',
        ]);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then
        $this->assertSame([
            'company' => ['id' => $this->company->getKey(), 'name' => $this->company->name],
            'role' => 'hr_officer',
            'joined_at' => now()->toISOString(),
            'emergency_contact' => [
                'name' => 'Nimali Perera',
                'email' => 'nimali@example.lk',
                'phone' => '+94771234567',
                'relationship' => 'Sister',
            ],
        ], $this->exportedData($request)['membership']);
    }

    #[Test]
    public function when_the_export_is_built_then_the_profile_section_holds_the_persons_profile(): void
    {
        // Given a member with two-factor sign-in who last signed in yesterday
        $member = User::factory()->withTwoFactor()->lastLoggedAt('2026-09-30 18:00:00')->create();
        $this->company->users()->attach($member, ['role_id' => $this->seededRole('employee')->getKey()]);
        $request = $this->aSubmittedRequest($this->company, $member);

        // When their export is built
        $this->runTheExportFor($request);

        // Then
        $this->assertSame([
            'id' => $member->getKey(),
            'name' => $member->name,
            'email' => $member->email,
            'locale' => $member->locale,
            'email_verified_at' => $member->email_verified_at?->toISOString(),
            'last_logged_at' => '2026-09-30T18:00:00.000000Z',
            'created_at' => $member->created_at?->toISOString(),
            'two_factor_enabled' => true,
        ], $this->exportedData($request)['profile']);
    }
}
