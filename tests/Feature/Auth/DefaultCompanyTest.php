<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use App\Companies\Entities\Company;
use Tests\Concerns\FreeTierCompany;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Companies\Entities\CompanyUser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * UC-1-139 Set Default Company (slice-companies Task 2) — the persisted
 * default-company preference behind PUT/DELETE /api/v1/me/default-company:
 * a plain users.default_company_id write (NOT event-sourced — B2), guarded
 * to live memberships only, collapsed to 422 on any non-live target (no
 * company-id-existence leak), idempotent on both verbs.
 */
class DefaultCompanyTest extends AuthTestCase
{
    use FreeTierCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeFreeTierCompany();
    }

    #[Test]
    public function when_setting_default_without_token_then_unauthenticated(): void
    {
        // Given no authenticated session

        // When
        $response = $this->putJson('/api/v1/me/default-company', [
            'companyId' => Str::uuid7()->toString(),
        ]);

        // Then
        $this->assertUnauthenticated($response);
    }

    #[Test]
    public function when_clearing_default_without_token_then_unauthenticated(): void
    {
        // Given no authenticated session

        // When
        $response = $this->deleteJson('/api/v1/me/default-company');

        // Then
        $this->assertUnauthenticated($response);
    }

    #[Test, DataProvider('malformedCompanyIdProvider')]
    public function when_company_id_is_missing_or_malformed_then_set_refused(array $payload, string $expectedMessage): void
    {
        // Given the employee, a live member of Contoso (the membership rule must never be reached)

        // When
        $response = $this->setDefaultCompany($this->employee, $payload);

        // Then
        $this->assertSetRefusedAsInvalid($response, $this->employee, $expectedMessage);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedCompanyIdProvider(): array
    {
        return [
            'missing' => [[], 'The company id field is required.'],
            'numeric' => [['companyId' => 123], 'The company id field must be a valid UUID.'],
            'not a uuid' => [['companyId' => 'not-a-uuid'], 'The company id field must be a valid UUID.'],
        ];
    }

    #[Test, DataProvider('nonLiveMembershipProvider')]
    public function when_target_company_is_not_a_live_membership_then_set_refused(string $case): void
    {
        // Given the employee of Contoso, whose target company is not a live membership
        $targetId = match ($case) {
            'not a member' => Company::factory()->named('Other')->create()->getKey(),
            'another user\'s company' => tap(
                Company::factory()->named('Other')->create(),
                fn (Company $other) => $this->attachMember($other, User::factory()->create(), $this->seededRole('employee')),
            )->getKey(),
            'unknown company id' => Str::uuid7()->toString(),
            'removed membership' => tap(
                Company::factory()->named('Other')->create(),
                fn (Company $other) => $this->aRemovedMembership($other, $this->employee),
            )->getKey(),
            default => self::fail("Unknown provider case: {$case}"),
        };

        // When
        $response = $this->setDefaultCompany($this->employee, ['companyId' => $targetId]);

        // Then — the same collapsed envelope for every variant (no existence leak)
        $this->assertSetRefusedLeavingDefault($response, $this->employee, null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonLiveMembershipProvider(): array
    {
        return [
            'not a member' => ['not a member'],
            'another user\'s company' => ['another user\'s company'],
            'unknown company id' => ['unknown company id'],
            'removed membership' => ['removed membership'],
        ];
    }

    #[Test]
    public function when_clearing_with_no_default_set_then_clear_still_succeeds(): void
    {
        // Given the employee, with no default company set

        // When
        $response = $this->clearDefaultCompany($this->employee);

        // Then — idempotent: 204 even though nothing was set (UC-1-139 alt-1a)
        $this->assertDefaultStored($response, $this->employee, null);
    }

    #[Test]
    public function when_setting_the_same_default_again_then_replace_is_idempotent(): void
    {
        // Given the employee whose default is already Contoso (set through the endpoint)
        $this->setDefaultCompany($this->employee, ['companyId' => $this->company->getKey()]);

        // When the same default is set again
        $response = $this->setDefaultCompany($this->employee, ['companyId' => $this->company->getKey()]);

        // Then — the idempotent replace: same 204, same stored value
        $this->assertDefaultStored($response, $this->employee, $this->company->getKey());
    }

    #[Test]
    public function when_setting_a_new_default_then_it_replaces_the_previous_one(): void
    {
        // Given the employee, a member of Contoso and Beta, whose default is Contoso
        $beta = Company::factory()->named('Beta')->create();
        $this->attachMember($beta, $this->employee, $this->seededRole('employee'));
        $this->setDefaultCompany($this->employee, ['companyId' => $this->company->getKey()]);

        // When Beta is set as the default
        $response = $this->setDefaultCompany($this->employee, ['companyId' => $beta->getKey()]);

        // Then — one default per user by construction: Beta replaced Contoso,
        // and the list re-flags exactly Beta (Contoso is not flagged)
        $this->assertDefaultStored($response, $this->employee, $beta->getKey());
        $this->assertListFlagsDefault($this->employee, $beta->getKey());
    }

    #[Test]
    public function when_set_is_refused_then_the_existing_default_is_retained(): void
    {
        // Given the employee whose default is Contoso (set through the endpoint)
        $this->setDefaultCompany($this->employee, ['companyId' => $this->company->getKey()]);

        // When they attempt to set a company they are not a member of
        $response = $this->setDefaultCompany($this->employee, [
            'companyId' => Company::factory()->named('Other')->create()->getKey(),
        ]);

        // Then — the refusal leaves the existing non-null default intact
        // (UC-1-139 Minimal Guarantee against a write-before-guard reorder)
        $this->assertSetRefusedLeavingDefault($response, $this->employee, $this->company->getKey());
    }

    #[Test]
    public function when_a_member_clears_their_default_then_other_users_defaults_are_untouched(): void
    {
        // Given the admin with Beta as default, then the owner and the employee with Contoso (a bulk
        // write on the set path would move the admin's; one on the clear path would clear the owner's)
        $beta = Company::factory()->named('Beta')->create();
        $this->attachMember($beta, $this->admin, $this->seededRole('employee'));
        $this->setDefaultCompany($this->admin, ['companyId' => $beta->getKey()]);
        $this->setDefaultCompany($this->owner, ['companyId' => $this->company->getKey()]);
        $this->setDefaultCompany($this->employee, ['companyId' => $this->company->getKey()]);

        // When the employee clears their default
        $response = $this->clearDefaultCompany($this->employee);

        // Then — the employee's is null; the admin's and the owner's are untouched (user-scoped writes)
        $this->assertDefaultStored($response, $this->employee, null);
        $this->assertDatabaseHas('users', ['id' => $this->admin->getKey(), 'default_company_id' => $beta->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $this->owner->getKey(), 'default_company_id' => $this->company->getKey()]);
    }

    #[Test]
    public function when_the_default_company_is_hard_deleted_then_the_default_clears(): void
    {
        // Given the employee whose default is Home (set through the endpoint)
        $home = Company::factory()->named('Home')->create();
        $this->attachMember($home, $this->employee, $this->seededRole('employee'));
        $this->setDefaultCompany($this->employee, ['companyId' => $home->getKey()]);

        // When the company row is hard-deleted (schema-level pin — no product
        // delete path exists until UC-1-037; the pivot goes first, its FK is
        // restrictOnDelete)
        CompanyUser::query()->where('company_id', $home->getKey())->forceDelete();
        Company::query()->whereKey($home->getKey())->forceDelete();

        // Then — the nullOnDelete FK clears the preference instead of stranding it
        $this->assertDatabaseHas('users', [
            'id' => $this->employee->getKey(),
            'default_company_id' => null,
        ]);
    }

    #[Test]
    public function when_member_sets_a_default_company_then_stored_and_flagged_in_list(): void
    {
        // Given the employee, a member of Contoso and Beta with no default
        $beta = Company::factory()->named('Beta')->create();
        $this->attachMember($beta, $this->employee, $this->seededRole('employee'));

        // When Beta is set as the default
        $response = $this->setDefaultCompany($this->employee, ['companyId' => $beta->getKey()]);

        // Then — stored AND the switcher list flags exactly that row (Gherkin 1)
        $this->assertDefaultStored($response, $this->employee, $beta->getKey());
        $this->assertListFlagsDefault($this->employee, $beta->getKey());
    }

    #[Test]
    public function when_member_clears_the_default_then_no_row_flagged_in_list(): void
    {
        // Given the employee, a member of Contoso and Beta, whose default is Beta
        $beta = Company::factory()->named('Beta')->create();
        $this->attachMember($beta, $this->employee, $this->seededRole('employee'));
        $this->setDefaultCompany($this->employee, ['companyId' => $beta->getKey()]);

        // When the default is cleared
        $response = $this->clearDefaultCompany($this->employee);

        // Then — stored null and the list flags nothing (Gherkin 3 + alt-1a)
        $this->assertDefaultStored($response, $this->employee, null);
        $this->assertListFlagsDefault($this->employee, null);
    }

    /**
     * The successful write oracle: the 204 AND the stored users.default_company_id
     * value (set target or null after clear).
     */
    protected function assertDefaultStored(TestResponse $response, User $user, ?string $companyId): void
    {
        $response->assertNoContent();
        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'default_company_id' => $companyId,
        ]);
    }

    /**
     * The shape-refused set: the §S21 422 VALIDATION_FAILED envelope keyed on
     * companyId with the rule's verbatim message AND no default written.
     */
    protected function assertSetRefusedAsInvalid(TestResponse $response, User $user, string $expectedMessage): void
    {
        $this->assertValidationRejected($response, 'companyId', $expectedMessage);
        $response->assertJsonCount(1, 'errors.companyId');
        $this->assertNull($user->refresh()->default_company_id);
    }

    /**
     * The membership-refused set: the §S21 422 VALIDATION_FAILED envelope keyed on
     * companyId, collapsed to one message for every non-live target (no
     * company-id-existence leak) AND the stored default untouched (UC-1-139
     * Minimal Guarantee).
     */
    protected function assertSetRefusedLeavingDefault(TestResponse $response, User $user, ?string $expectedDefault): void
    {
        $this->assertValidationRejected($response, 'companyId', 'The selected company is invalid.');
        $response->assertJsonCount(1, 'errors.companyId');
        $this->assertSame($expectedDefault, $user->refresh()->default_company_id);
    }
}
