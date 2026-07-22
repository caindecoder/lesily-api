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
}
