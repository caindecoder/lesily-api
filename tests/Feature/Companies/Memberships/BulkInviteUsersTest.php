<?php

namespace Tests\Feature\Companies\Memberships;

use App\Models\User;
use Illuminate\Http\Response;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;
use App\Companies\Memberships\Mail\InvitationMail;

class BulkInviteUsersTest extends MembershipsTestCase
{
    #[Test]
    public function when_the_request_carries_no_token_then_it_is_unauthenticated(): void
    {
        // Given a company with an admin
        [$company] = $this->aCompanyWithMember('admin');

        // When a batch is posted with no token
        $response = $this->postJson('/api/v1/invitations/bulk', ['invitations' => $this->rowsFor(['newuser@contoso.lk'])], ['X-Company-Context' => $company->getKey()]);

        // Then the request is unauthenticated and nothing is created
        $this->assertRefusedWith($response, Response::HTTP_UNAUTHORIZED, ApiErrorCode::Unauthenticated);
        $this->assertNoInvitationCreated();
    }

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
     * @param  ?array<array-key, array{email: string, roleCode: string}>  $rows
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
     * @param  array<string, string>|string  $malformedRow
     */
    #[Test]
    #[DataProvider('provideMalformedRows')]
    public function when_a_row_is_malformed_then_the_batch_is_refused_naming_that_row(array|string $malformedRow, string $invalidField, string $message): void
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
     * @return array<string, array{array<string, string>|string, string, string}>
     */
    public static function provideMalformedRows(): array
    {
        return [
            'a row that is not an object' => ['not-a-row', 'invitations.1.email', 'The invitations.1.email field is required.'],
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
        $this->assertValidationRejected($response, 'invitations.0.email', 'The invitations.0.email field must be a valid email address.');
        $this->assertValidationRejected($response, 'invitations.1.email', 'The invitations.1.email field must be a valid email address.');
        $this->assertNoInvitationCreated();
    }

    #[Test]
    public function when_the_batch_exceeds_the_allowance_left_this_hour_then_nothing_is_sent(): void
    {
        // Given an admin who has sent 18 of the 20 invitations allowed this hour
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $this->freezeTime();
        $this->exhaustInviteLimit($admin, $company, 18);

        // When they bulk invite five more
        $response = $this->bulkInvite($admin, $company, $this->batchOf(5));

        // Then the batch is refused until the hour is up and nothing more is invited or mailed
        $this->assertAllowanceRefusedFor($response, 3600);
        $this->assertInvitationsSentInTotal(18);
    }

    #[Test]
    public function when_a_malformed_batch_is_refused_then_it_spends_no_allowance(): void
    {
        // Given an admin whose twenty-row batch was refused for one malformed row
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $refused = $this->batchOf(20);
        $refused[19]['email'] = 'not-an-email';
        $this->bulkInvite($admin, $company, $refused);
        $rows = $this->batchOf(20);

        // When they send the corrected batch
        $response = $this->bulkInvite($admin, $company, $rows);

        // Then all twenty rows are sent
        $this->assertBatchSent($response, $company, $rows);
    }

    #[Test]
    public function when_the_batch_fits_exactly_the_allowance_left_then_every_row_is_sent(): void
    {
        // Given an admin who has sent 18 of the 20 invitations allowed this hour
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $this->exhaustInviteLimit($admin, $company, 18);
        $rows = $this->batchOf(2);

        // When they bulk invite the last two
        $response = $this->bulkInvite($admin, $company, $rows);

        // Then both rows are sent, twenty in all
        $this->assertRowsSentInOrder($response, $rows);
        $this->assertInvitationsSentInTotal(20);
    }

    #[Test]
    public function when_a_batch_has_spent_the_allowance_then_a_single_invite_is_throttled(): void
    {
        // Given an admin who has bulk invited twenty this hour
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $this->bulkInvite($admin, $company, $this->batchOf(20));

        // When they invite one more on its own
        $response = $this->invite($admin, $company, ['email' => 'one-more@contoso.lk', 'roleCode' => 'employee']);

        // Then the single invite is throttled and nothing more is invited or mailed
        $this->assertThrottled($response);
        $this->assertInvitationsSentInTotal(20);
    }

    #[Test]
    public function when_the_hour_has_not_passed_then_the_allowance_stays_spent(): void
    {
        // Given an admin who bulk invited twenty one second short of an hour ago
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $this->freezeTime();
        $this->bulkInvite($admin, $company, $this->batchOf(20));
        $this->travel(3599)->seconds();

        // When they bulk invite one more
        $response = $this->bulkInvite($admin, $company, $this->rowsFor(['too-soon@contoso.lk']));

        // Then the batch is refused for the last second and nothing more is invited or mailed
        $this->assertAllowanceRefusedFor($response, 1);
        $this->assertInvitationsSentInTotal(20);
    }

    #[Test]
    public function when_the_hour_has_passed_then_the_allowance_is_restored(): void
    {
        // Given an admin who bulk invited twenty exactly an hour ago
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $this->freezeTime();
        $this->bulkInvite($admin, $company, $this->batchOf(20));
        $this->travel(3600)->seconds();
        $rows = $this->rowsFor(['next-hour@contoso.lk']);

        // When they bulk invite one more
        $response = $this->bulkInvite($admin, $company, $rows);

        // Then the row is sent, twenty-one in all
        $this->assertRowsSentInOrder($response, $rows);
        $this->assertInvitationsSentInTotal(21);
    }

    #[Test]
    public function when_an_email_repeats_in_the_batch_then_the_repeat_is_skipped(): void
    {
        // Given an admin of a company
        [$company, $admin] = $this->aCompanyWithMember('admin');

        // When the admin submits the same email twice in one batch
        $response = $this->bulkInvite($admin, $company, $this->rowsFor(['nimal@contoso.lk', 'nimal@contoso.lk']));

        // Then the repeat is skipped as already invited and the email is invited once
        $this->assertRowSkipped($response, 1, 'This email has already been invited to this company.');
        $this->assertInvitedAndMailedOnce($company, ['nimal@contoso.lk']);
    }

    #[Test]
    public function when_some_rows_are_members_or_already_invited_then_those_rows_are_skipped_and_the_rest_sent(): void
    {
        // Given an admin, a member of the company and an email already invited
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $member = User::factory()->withEmail('member@contoso.lk')->create();
        $this->attachMember($company, $member, $this->seededRole('employee'));
        $this->aPendingInvitation($company, $admin, 'pending@contoso.lk');

        // When the admin bulk invites two new emails, the member and the invited email
        $response = $this->bulkInvite($admin, $company, $this->rowsFor([
            'nimal@contoso.lk', 'member@contoso.lk', 'pending@contoso.lk', 'sunil@contoso.lk',
        ]));

        // Then the member and the invited rows are skipped with their reasons, and
        // only the new emails gain an invitation and a mail
        $this->assertRowSkipped($response, 1, 'This user is already a member of this company.');
        $this->assertRowSkipped($response, 2, 'This email has already been invited to this company.');
        $this->assertInvitedAndMailedOnce($company, ['nimal@contoso.lk', 'pending@contoso.lk', 'sunil@contoso.lk']);
        $this->assertDatabaseMissing('invitations', ['email' => 'member@contoso.lk']);
    }

    #[Test]
    #[DataProvider('provideInvitingRoles')]
    public function when_an_owner_admin_or_hr_officer_bulk_invites_then_the_rows_are_sent(string $roleCode): void
    {
        // Given a member whose role carries users.invite
        [$company, $actor] = $this->aCompanyWithMember($roleCode);
        $rows = $this->rowsFor(['nimal@contoso.lk', 'sunil@contoso.lk']);

        // When they bulk invite two new emails
        $response = $this->bulkInvite($actor, $company, $rows);

        // Then both rows are sent
        $this->assertBatchSent($response, $company, $rows);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvitingRoles(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'hr officer' => ['hr_officer'],
        ];
    }

    #[Test]
    public function when_valid_rows_are_submitted_then_each_is_invited_with_its_own_role_and_mailed(): void
    {
        // Given an admin of a company and three new emails, each with its own role
        [$company, $admin] = $this->aCompanyWithMember('admin');
        $rows = [
            ['email' => 'nimal@contoso.lk', 'roleCode' => 'employee'],
            ['email' => 'sunil@contoso.lk', 'roleCode' => 'hr_officer'],
            ['email' => 'kumari@contoso.lk', 'roleCode' => 'auditor'],
        ];

        // When the admin bulk invites them
        $response = $this->bulkInvite($admin, $company, $rows);

        // Then each row is sent in order, invited with its own role and mailed
        $this->assertBatchSent($response, $company, $rows);
    }

    /**
     * @return array<string, array{?array<array-key, array{email: string, roleCode: string}>, string}>
     */
    public static function provideBadBatchSizes(): array
    {
        $twentyOne = array_map(fn (int $i): array => ['email' => sprintf('invitee-%02d@contoso.lk', $i), 'roleCode' => 'employee'], range(1, 21));

        return [
            'missing' => [null, 'The invitations field is required.'],
            'empty' => [[], 'The invitations field is required.'],
            'twenty-one rows' => [$twentyOne, 'The invitations field must not have more than 20 items.'],
            'rows keyed by name, not a list' => [['first' => ['email' => 'first@contoso.lk', 'roleCode' => 'employee']], 'The invitations field must be a list.'],
        ];
    }

    /**
     * @return list<array{email: string, roleCode: string}>
     */
    protected function batchOf(int $size): array
    {
        return $this->rowsFor(array_map(fn (int $i): string => sprintf('batch-%02d@contoso.lk', $i), range(1, $size)));
    }

    protected function assertAllowanceRefusedFor(TestResponse $response, int $seconds): void
    {
        $this->assertThrottled($response);
        $response->assertJsonPath('message', 'Too many requests. Please try again later.');
        $response->assertJsonPath('retry_after', $seconds);
        $response->assertHeader('Retry-After', (string) $seconds);
    }

    protected function assertInvitationsSentInTotal(int $expected): void
    {
        $this->assertDatabaseCount('invitations', $expected);
        Mail::assertQueued(InvitationMail::class, $expected);
    }

    protected function assertRowSkipped(TestResponse $response, int $index, string $message): void
    {
        $response->assertOk();
        $response->assertJsonPath("data.{$index}.status", 'skipped');
        $response->assertJsonPath("data.{$index}.error_code", ApiErrorCode::AlreadyMember->value);
        $response->assertJsonPath("data.{$index}.message", $message);
    }

    /**
     * Each email holds exactly one pending invitation in the company and was mailed once.
     *
     * @param  list<string>  $emails
     */
    protected function assertInvitedAndMailedOnce(Company $company, array $emails): void
    {
        foreach ($emails as $email) {
            $this->assertPendingInvitationCreated($company, $email, 'employee');
            $this->assertCount(1, Mail::queued(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo($email)));
        }
    }
}
