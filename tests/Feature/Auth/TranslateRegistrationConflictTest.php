<?php

namespace Tests\Feature\Auth;

use RuntimeException;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Auth\UseCases\RegistrationUniquenessGuard;

/**
 * Registration-conflict translation at the domain seam (conv-plan §2.14 Rule C):
 * the register path owns no unique index outside email / BR / EPF, so a violation
 * the guard cannot map is unreachable via HTTP — it guards the re-raise contract
 * (an unmappable violation must never be disguised as a registration conflict) and
 * is provable only at this seam.
 *
 * Provenance: relocated from RegisterOwnerSadPathTest at the s142 partition
 * consolidation. RegisterOwnerTest's unmapped-violation walk STUBS conflictFor, so
 * it proves only the caller's re-raise — the real default arm is executed here and
 * nowhere else. That distinction was caught by both independent agents at retrofit
 * B2 (PR #108), where the parity oracle scored zero delta across the same gap.
 */
class TranslateRegistrationConflictTest extends AuthTestCase
{
    // Sad — a unique violation on a constraint outside the registration field set
    // (email / BR / EPF) must NOT be disguised as a registration conflict.
    #[Test]
    public function when_a_unique_violation_is_on_an_unmapped_constraint_then_no_registration_conflict_is_translated(): void
    {
        // Given a unique violation on some constraint the guard does not map
        $violation = $this->uniqueViolationOnIndex('some_other_unique');

        // When the guard is asked to translate it into a registration conflict
        $conflict = (new RegistrationUniquenessGuard)->conflictFor($violation);

        // Then there is no conflict to raise — the caller re-throws the original error
        $this->assertNull($conflict);
    }

    protected function uniqueViolationOnIndex(string $index): UniqueConstraintViolationException
    {
        return (new UniqueConstraintViolationException(
            'mysql',
            'insert into `companies` (`x`) values (?)',
            ['x'],
            new RuntimeException("Duplicate entry 'x' for key '{$index}'"),
        ))->setIndex($index);
    }
}
