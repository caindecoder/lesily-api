<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use RuntimeException;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Auth\UseCases\RegistrationUniquenessGuard;

class RegistrationUniquenessGuardTest extends TestCase
{
    // Edge — a unique violation on a constraint outside the registration field set
    // (email / BR / EPF) must NOT be disguised as a registration conflict. The
    // original database error has to surface so a genuine fault is never masked as a 409.
    #[Test]
    public function when_a_unique_violation_is_on_an_unmapped_constraint_then_the_original_error_is_not_masked(): void
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
