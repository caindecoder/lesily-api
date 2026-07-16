<?php

namespace Tests\Feature\PlatformAdmin;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\QueryException;
use App\Foundation\AuditTrail\Entities\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice-plans-billing T6 — the audit_trail foundation (erd-audit-trail /
 * ERD-1-11): append-only table + AuditEntry model + factory, written first by
 * UC-1-122's read path at T7 (direct insert, NULL stored_event_id). Immutable
 * by convention — no update/delete paths are built (spec §6; the enforcement
 * scenario belongs to the UC-1-066 slice).
 *
 * @testclass-carveout: infra-primitive — no HTTP seam exists until T7 wires
 * the first production writer; these pins prove the schema/model/factory
 * primitive the T7 behavior walk will stand on.
 */
class AppendAuditEntryTest extends PlatformAdminTestCase
{
    use RefreshDatabase;

    // Sad — the schema rejects an entry missing a required field: action is
    // NOT NULL per ERD-1-11 §3. The assertion pins the SPECIFIC violation
    // (SQLSTATE 23000 naming the column) so a missing TABLE (42S02) cannot
    // false-pass this test.
    #[Test]
    public function when_appending_an_entry_missing_a_required_field_then_insert_is_rejected(): void
    {
        // Given an otherwise-valid entry with no action
        $entry = AuditEntry::factory()->make();
        $entry->action = null;

        // When it is appended
        try {
            $entry->save();
            $this->fail('Expected the NOT NULL constraint on action to reject the insert.');
        } catch (QueryException $e) {
            // Then the insert is rejected by the integrity constraint on that column
            $this->assertNotNullViolationOn($e, 'action');
        }
    }
}
