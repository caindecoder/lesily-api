<?php

namespace Tests\Feature\Foundation\AuditTrail;

use App\Models\User;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Foundation\AuditTrail\Entities\AuditEntry;

class ViewAuditTrailTest extends AuditTrailTestCase
{
    #[Test]
    public function when_a_guest_views_the_audit_trail_then_they_are_unauthenticated(): void
    {
        // Given company A has an audit entry
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();

        // When a caller with no token views company A's audit trail
        $response = $this->viewAuditTrail(null, $this->companyA);

        // Then they are refused as unauthenticated and no entry is shown
        $this->assertUnauthenticated($response);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutTheAuditPermission')]
    public function when_a_member_without_the_audit_permission_views_then_the_role_is_insufficient(string $roleCode): void
    {
        // Given company A has an audit entry, and a member of A whose role cannot view the audit trail
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $member = $this->aConsentingMember($this->companyA, $roleCode);

        // When the member views company A's audit trail
        $response = $this->viewAuditTrail($member, $this->companyA);

        // Then the role is refused and no entry is shown
        $this->assertRoleInsufficient($response);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_a_member_of_another_company_views_then_company_access_is_denied(): void
    {
        // Given company A has an audit entry, and the owner of company B
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $outsider = $this->aConsentingMember($this->companyB, 'owner');

        // When B's owner views company A's audit trail
        $response = $this->viewAuditTrail($outsider, $this->companyA);

        // Then access to company A is denied and no entry is shown
        $this->assertCompanyAccessDenied($response);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    public function when_a_member_owes_a_new_policy_version_then_consent_is_required(): void
    {
        // Given company A has an audit entry, and A's owner accepted the policies before a new privacy policy version
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');
        config(['lesily.policies.privacy' => 'v1.1']);

        // When the owner views company A's audit trail
        $response = $this->viewAuditTrail($owner, $this->companyA);

        // Then the audit trail is refused until they accept, and no entry is shown
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::ConsentRequired);
        $response->assertJsonMissingPath('data');
    }

    #[Test]
    #[DataProvider('provideWriteVerbs')]
    public function when_a_write_verb_is_sent_to_the_audit_trail_then_the_method_is_not_allowed(string $verb): void
    {
        // Given company A has an audit entry, and A's owner
        AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner sends a write verb to company A's audit trail
        $response = $this->sendToAuditTrail($verb, $owner, '/api/v1/audit-trail');

        // Then no route writes it, and the entry is kept
        $this->assertRefusedWith($response, Response::HTTP_METHOD_NOT_ALLOWED, ApiErrorCode::HttpError);
        $this->assertDatabaseCount('audit_trail', 1);
    }

    #[Test]
    #[DataProvider('provideEntryVerbs')]
    public function when_an_entry_is_addressed_by_its_id_then_no_route_answers_and_it_is_unchanged(string $verb): void
    {
        // Given company A has an audit entry, and A's owner
        $entry = AuditEntry::factory()->forCompany($this->companyA)->byMember(User::factory()->create(), 'admin')->create();
        $owner = $this->aConsentingMember($this->companyA, 'owner');

        // When the owner sends the verb to the entry's own address
        $response = $this->sendToAuditTrail($verb, $owner, '/api/v1/audit-trail/'.$entry->getKey());

        // Then no route answers, and the entry is unchanged
        $response->assertNotFound();
        $this->assertDatabaseHas('audit_trail', ['id' => $entry->getKey(), 'description' => $entry->description]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutTheAuditPermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideWriteVerbs(): array
    {
        return [
            'post' => ['POST'],
            'put' => ['PUT'],
            'patch' => ['PATCH'],
            'delete' => ['DELETE'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEntryVerbs(): array
    {
        return [
            'delete' => ['DELETE'],
            'patch' => ['PATCH'],
        ];
    }
}
