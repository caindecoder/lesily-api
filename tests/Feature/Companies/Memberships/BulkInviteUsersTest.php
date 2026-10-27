<?php

namespace Tests\Feature\Companies\Memberships;

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
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

    /**
     * @param  ?list<array{email: string, roleCode: string}>  $rows
     */
    #[Test]
    #[DataProvider('provideBadBatchSizes')]
    public function when_the_batch_is_missing_empty_or_over_twenty_rows_then_it_is_refused(?array $rows, string $message): void
    {
        // Given an admin of a company
        [$company, $admin] = $this->aCompanyWithMember('admin');

        // When the admin submits a batch with no rows, or with more than twenty
        $response = $this->bulkInvite($admin, $company, $rows);

        // Then the batch is refused as invalid and nothing is created
        $this->assertValidationRejected($response, 'invitations', $message);
        $this->assertNoInvitationCreated();
    }

    /**
     * @param  array<string, string>  $malformedRow
     */
    #[Test]
    #[DataProvider('provideMalformedRows')]
    public function when_a_row_is_malformed_then_the_batch_is_refused_naming_that_row(array $malformedRow, string $invalidField, string $message): void
    {
        // Given an admin of a company
        [$company, $admin] = $this->aCompanyWithMember('admin');

        // When the admin submits three rows, the middle one malformed
        $response = $this->bulkInvite($admin, $company, [
            ['email' => 'first@contoso.lk', 'roleCode' => 'employee'],
            $malformedRow,
            ['email' => 'third@contoso.lk', 'roleCode' => 'employee'],
        ]);

        // Then the batch is refused naming that row's field and nothing is created
        $this->assertValidationRejected($response, $invalidField, $message);
        $this->assertNoInvitationCreated();
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function provideMalformedRows(): array
    {
        return [
            'malformed email' => [['email' => 'not-an-email', 'roleCode' => 'employee'], 'invitations.1.email', 'The invitations.1.email field must be a valid email address.'],
            'empty email' => [['email' => '', 'roleCode' => 'employee'], 'invitations.1.email', 'The invitations.1.email field is required.'],
            'missing email' => [['roleCode' => 'employee'], 'invitations.1.email', 'The invitations.1.email field is required.'],
            'owner role' => [['email' => 'second@contoso.lk', 'roleCode' => 'owner'], 'invitations.1.roleCode', 'The selected invitations.1.roleCode is invalid.'],
            'unknown role' => [['email' => 'second@contoso.lk', 'roleCode' => 'super_admin'], 'invitations.1.roleCode', 'The selected invitations.1.roleCode is invalid.'],
            'missing role code' => [['email' => 'second@contoso.lk'], 'invitations.1.roleCode', 'The invitations.1.roleCode field is required.'],
        ];
    }

    #[Test]
    public function when_every_row_is_malformed_then_each_row_is_named(): void
    {
        // Given an admin of a company
        [$company, $admin] = $this->aCompanyWithMember('admin');

        // When the admin submits only malformed rows
        $response = $this->bulkInvite($admin, $company, $this->rowsFor(['not-an-email', 'also-not-an-email']));

        // Then every malformed row is named and nothing is created
        $this->assertRowsRejected($response, [
            'invitations.0.email' => 'The invitations.0.email field must be a valid email address.',
            'invitations.1.email' => 'The invitations.1.email field must be a valid email address.',
        ]);
        $this->assertNoInvitationCreated();
    }

    /**
     * @return array<string, array{?list<array{email: string, roleCode: string}>, string}>
     */
    public static function provideBadBatchSizes(): array
    {
        $twentyOne = array_map(fn (int $i): array => ['email' => sprintf('invitee-%02d@contoso.lk', $i), 'roleCode' => 'employee'], range(1, 21));

        return [
            'missing' => [null, 'The invitations field is required.'],
            'empty' => [[], 'The invitations field is required.'],
            'twenty-one rows' => [$twentyOne, 'The invitations field must not have more than 20 items.'],
        ];
    }

    /**
     * @param  array<string, string>  $messagesByField
     */
    protected function assertRowsRejected(TestResponse $response, array $messagesByField): void
    {
        $response->assertUnprocessable();
        $response->assertJsonPath('error_code', ApiErrorCode::ValidationFailed->value);
        $response->assertJsonValidationErrors($messagesByField);
    }
}
