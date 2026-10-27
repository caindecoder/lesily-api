<?php

namespace Tests\Feature\Companies\Memberships;

use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

class BulkInviteUsersTest extends MembershipsTestCase
{
    #[Test]
    public function when_the_actor_belongs_to_another_company_then_company_access_is_denied(): void
    {
        // Given an admin of one company and a second company they never joined
        [, $outsider] = $this->aCompanyWithMember('admin');
        [$otherCompany] = $this->aCompanyWithMember('owner');

        // When they bulk invite into the second company
        $response = $this->bulkInvite($outsider, $otherCompany, $this->rowsFor(['newuser@contoso.lk']));

        // Then the company context is denied and nothing is created
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::CompanyAccessDenied);
        $this->assertNoInvitationCreated();
    }

    #[Test]
    #[DataProvider('provideNonInvitingRoles')]
    public function when_the_actor_role_lacks_the_invite_permission_then_the_batch_is_refused(string $roleCode): void
    {
        // Given a member whose role does not carry users.invite
        [$company, $actor] = $this->aCompanyWithMember($roleCode);

        // When they bulk invite a new email
        $response = $this->bulkInvite($actor, $company, $this->rowsFor(['newuser@contoso.lk']));

        // Then the batch is refused for the missing permission and nothing is created
        $this->assertRefusedWith($response, Response::HTTP_FORBIDDEN, ApiErrorCode::RoleInsufficient);
        $this->assertNoInvitationCreated();
    }
}
