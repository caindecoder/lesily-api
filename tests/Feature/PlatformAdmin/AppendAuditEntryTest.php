<?php

namespace Tests\Feature\PlatformAdmin;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
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
 * @testclass-carveout: infra-primitive — the HTTP-side write is covered by
 * ReviewInvoiceAsAdminTest (T7, PR #105, via
 * ReviewInvoiceAsAdminInteractor::recordAdminReview); these pins prove the
 * append-only schema/model/factory primitive beneath that walk.
 */
class AppendAuditEntryTest extends PlatformAdminTestCase
{
    use RefreshDatabase;

    // Sad — the schema rejects an entry missing a required field: action is
    // NOT NULL per ERD-1-11 §3 and stands as the REPRESENTATIVE pin for the
    // schema's required set (event_class/subject_type share the same
    // constraint class — TA-4.3; subject_id left that set at UC-1-127's
    // nullable migration, since a list-page read has no single subject).
    // The assertion pins the SPECIFIC violation (SQLSTATE 23000 + the driver
    // message naming the column) so neither a missing TABLE (42S02) nor a
    // violation on a different column can false-pass this test.
    #[Test]
    public function when_appending_an_entry_missing_a_required_field_then_insert_is_rejected(): void
    {
        // Given an otherwise-valid entry with no action (setAttribute pushes
        // null past the string property type on purpose — the DB constraint
        // is the thing under test)
        $entry = AuditEntry::factory()->make();
        $entry->setAttribute('action', null);

        // When it is appended
        try {
            $entry->save();
            $this->fail('Expected the NOT NULL constraint on action to reject the insert.');
        } catch (QueryException $e) {
            // Then the insert is rejected by the integrity constraint on that column
            $this->assertNotNullViolationOn($e, 'action');
        }
    }

    // Edge — a platform-level system row holds the nullable scopes at once:
    // no tenant (company_id NULL — platform events, ERD-1-11 §3), no human
    // actor (system-driven), and no source domain event (the direct-write
    // case T7 rides); subject_id joined the nullable set at UC-1-127. Teeth:
    // REDs if any of these is ever tightened to NOT NULL.
    #[Test]
    public function when_appending_a_platform_level_system_entry_then_nullable_scopes_hold(): void
    {
        // Given a platform-level, system-driven entry outside the event flow
        $entry = AuditEntry::factory()->platformLevel()->system()->create();

        // Then the row persists with the full nullable trio
        $this->assertDatabaseHas('audit_trail', [
            'id' => $entry->getKey(),
            'company_id' => null,
            'actor_user_id' => null,
            'stored_event_id' => null,
        ]);
    }

    // Edge — stored_event_id is a SOFT FK (ERD-1-11 §5: application-layer
    // reference, deliberately no DB constraint): a row pointing at a
    // stored_events id that does not exist still inserts. REDs if anyone
    // "tightens" the column into a hard FK, which would break tooling-written
    // rows and the typing boundary the ERD rules out.
    #[Test]
    public function when_appending_an_entry_with_an_unmatched_stored_event_id_then_insert_succeeds(): void
    {
        // Given an entry referencing a stored event that does not exist
        $entry = AuditEntry::factory()->fromStoredEvent(999999)->create();

        // Then the row persists carrying the unmatched reference
        $this->assertDatabaseHas('audit_trail', [
            'id' => $entry->getKey(),
            'stored_event_id' => 999999,
        ]);
    }

    // Edge — the two declared casts are live behavior, not decoration
    // (independent-review findings TA-3.2/4.2): structured metadata written
    // as an array reads back as the same array (JSON round-trip), and
    // recorded_at hydrates as a datetime. Teeth: the lap-11 revert-check
    // removed the casts and this REDded.
    #[Test]
    public function when_an_entry_carries_metadata_then_casts_round_trip(): void
    {
        // Given an entry carrying an event-class-specific metadata shape
        $metadata = ['reason' => 'dispute triage', 'evidence_ref' => 'TICKET-4821'];
        $entry = AuditEntry::factory()->withMetadata($metadata)->create();

        // When it is read back from the table
        $fresh = $entry->refresh();

        // Then both declared casts hydrate the stored values
        $this->assertSame($metadata, $fresh->metadata);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->recorded_at);
    }

    // Happy — the factory's tenant-scoped direct-write row (the UC-1-122
    // shape) persists exactly as the append-only design demands: created_at
    // stamped, severity falling to the DB default (the factory omits it), and
    // no mutation columns in the table at all. Teeth: the lap-9 revert-check
    // switched the migration to timestamps() and this REDded on updated_at.
    #[Test]
    public function when_appending_a_tenant_scoped_entry_then_row_persists_append_only(): void
    {
        // Given a tenant-scoped admin-review entry
        $entry = AuditEntry::factory()->create();

        // Then the row persists append-only with the DB-default severity
        $this->assertAppendOnlyPersistence($entry);
    }

    /**
     * The row persisted append-only — created_at stamped, severity defaulted
     * to 'info' by the DATABASE (the factory omits the column, so the default
     * is the live path), and the table carries neither updated_at nor
     * deleted_at (the ERD-1-11 append-only deviation).
     */
    protected function assertAppendOnlyPersistence(AuditEntry $entry): void
    {
        $this->assertDatabaseHas('audit_trail', [
            'id' => $entry->getKey(),
            'severity' => 'info',
        ]);
        $this->assertNotNull($entry->fresh()?->created_at);
        $this->assertFalse(Schema::hasColumn('audit_trail', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('audit_trail', 'deleted_at'));
    }
}
