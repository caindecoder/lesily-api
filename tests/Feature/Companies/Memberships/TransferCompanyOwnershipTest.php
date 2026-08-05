<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Companies\Entities\Company;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Foundation\Http\Responses\ApiErrorCode;
use Tests\Feature\Companies\CreatesTrialCompanies;
use App\Companies\Events\CompanyOwnershipTransferred;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Foundation\EventStore\StoredEvents\Models\LesilyStoredEvent;

/**
 * Owner hands company ownership to an existing teammate in one in-app action
 * (UC-1-028, slice-11R spec §4/§6): the column and both pivot roles move
 * together under the interactor's row locks, or not at all.
 */
class TransferCompanyOwnershipTest extends MembershipsTestCase
{
    use CreatesTrialCompanies;

    #[Test]
    public function when_a_non_owner_member_attempts_the_transfer_then_it_is_refused(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        [$admin] = $this->aMembership($company, 'admin');
        [$target] = $this->aMembership($company, 'employee');

        // When
        $response = $this->transferOwnership($admin, $company, ['newOwnerUserId' => $target->getKey()]);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
        $this->assertNothingTransferred($company, $owner);
    }

    #[Test]
    public function when_the_target_is_not_a_member_of_the_company_then_the_transfer_is_refused(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        $outsider = User::factory()->create();

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $outsider->getKey()]);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::TargetNotMember);
        $this->assertNothingTransferred($company, $owner);
    }

    #[Test]
    public function when_the_target_holds_only_a_pending_invitation_then_the_transfer_is_refused(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);
        $invitee = User::factory()->create();
        $this->aPendingInvitation($company, $owner, $invitee->email);

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $invitee->getKey()]);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::TargetNotMember);
        $this->assertNothingTransferred($company, $owner);
        $this->assertInvitationNotConsumed($company, $invitee->email);
    }

    #[Test]
    public function when_the_owner_targets_themselves_then_the_transfer_is_refused(): void
    {
        // Given
        $owner = User::factory()->create();
        $company = $this->aTrialCompanyOwnedBy($owner);

        // When
        $response = $this->transferOwnership($owner, $company, ['newOwnerUserId' => $owner->getKey()]);

        // Then
        $this->assertRefusedWith($response, ApiErrorCode::SelfTransferBlocked);
        $this->assertNothingTransferred($company, $owner);
    }

    /**
     * A genesis company owned by $owner: real Company stream, owner pivot and
     * companies.owner_id both written by the production genesis projector.
     */
    protected function aTrialCompanyOwnedBy(User $owner, string $name = 'Contoso'): Company
    {
        $companyId = (string) Str::uuid7();

        CompanyAggregate::retrieve($companyId)
            ->create($this->buildCreateCompanyCommand($companyId, $name, $owner->getKey()))
            ->persist();

        return Company::query()->findOrFail($companyId);
    }

    /**
     * Transfer $company's ownership as $actor. Param route — the URL is
     * authoritative (R8), no X-Company-Context header.
     *
     * @param  array<string, mixed>  $body
     */
    protected function transferOwnership(User $actor, Company $company, array $body): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->patchJson("/api/v1/companies/{$company->getKey()}/owner", $body);
    }

    /**
     * A refused transfer changed nothing: companies.owner_id still names
     * $expectedOwner and this company's aggregate recorded no transfer event.
     */
    protected function assertNothingTransferred(Company $company, User $expectedOwner): void
    {
        $this->assertSame($expectedOwner->getKey(), $company->fresh()?->owner_id);
        $this->assertSame(0, LesilyStoredEvent::query()
            ->where('event_class', CompanyOwnershipTransferred::class)
            ->where('aggregate_uuid', $company->getKey())
            ->count());
    }
}
