<?php

namespace Tests\Feature\Companies\OwnershipTransfers;

use App\Companies\Entities\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Foundation\Http\Responses\ApiErrorCode;

/**
 * UC-1-126 — View Ownership Transfer History (slice #11 Task 2): the company's
 * Owner / Admin / Auditor read the paginated, newest-first list of every
 * ownership-transfer record for their company (all states incl. terminals),
 * tenant-scoped, with a `?state=` filter.
 */
class ViewOwnershipTransferHistoryTest extends OwnershipTransfersTestCase
{
    // --- Sad ---

    #[Test]
    public function when_the_request_is_unauthenticated_then_it_is_rejected(): void
    {
        // Given no authenticated session

        // When the transfer history is requested
        $response = $this->getTransferHistoryAsGuest();

        // Then it is refused as unauthenticated
        $this->assertRefusedWith($response, ApiErrorCode::Unauthenticated);
    }

    #[Test]
    #[DataProvider('provideRolesWithoutViewPermission')]
    public function when_the_actor_lacks_the_view_permission_then_it_is_denied(string $roleCode): void
    {
        // Given a company member holding a role without transfer-view rights
        $company = Company::factory()->create();
        $actor = $this->aMemberWithRole($company, $roleCode);

        // When they request the transfer history
        $response = $this->getTransferHistory($actor, $company);

        // Then it is denied for lacking the view-history permission
        $this->assertRefusedWith($response, ApiErrorCode::RoleInsufficient);
    }

    /**
     * The tenant roles the matrix Module 8 denies transfer-view (owner + admin +
     * auditor are the ✅/👁️ rows; these four are ❌).
     *
     * @return array<string, array{string}>
     */
    public static function provideRolesWithoutViewPermission(): array
    {
        return [
            'hr officer' => ['hr_officer'],
            'payroll officer' => ['payroll_officer'],
            'employee' => ['employee'],
            'manager' => ['manager'],
        ];
    }

    #[Test]
    #[DataProvider('provideInvalidQueryInputs')]
    public function when_the_query_input_is_invalid_then_it_is_rejected(array $query, string $invalidField): void
    {
        // Given a company owner (granted view-history)
        [$company, $owner] = $this->aCompanyWithOwner();

        // When they request the history with an unsupported query parameter
        $response = $this->getTransferHistory($owner, $company, $query);

        // Then it is refused as shape-invalid on the offending field
        $this->assertValidationRejected($response, $invalidField);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidQueryInputs(): array
    {
        return [
            'unknown filter key' => [['foo' => 'bar'], 'foo'],
            'non-canonical state' => [['state' => 'completed'], 'state'],
            'per_page over the ceiling' => [['per_page' => 101], 'per_page'],
            'page below one' => [['page' => 0], 'page'],
            'non-integer page' => [['page' => 'abc'], 'page'],
            'non-whitelisted sort' => [['sort' => 'name'], 'sort'],
        ];
    }

    // --- Edge ---

    #[Test]
    public function when_the_history_is_empty_then_the_envelope_has_empty_data_and_a_zero_total(): void
    {
        // Given a company owner with no transfer records
        [$company, $owner] = $this->aCompanyWithOwner();

        // When they request the history
        $response = $this->getTransferHistory($owner, $company);

        // Then the list is an empty data array with a zero-total pagination envelope
        $response->assertOk();
        $response->assertExactJson([
            'data' => [],
            'meta' => ['pagination' => ['page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
        ]);
    }
}
