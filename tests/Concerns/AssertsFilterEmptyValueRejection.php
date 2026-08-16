<?php

namespace Tests\Concerns;

use Spatie\LaravelData\Data;
use PHPUnit\Framework\TestCase;
use Illuminate\Validation\ValidationException;

/**
 * DTO-seam guard for the Filters family (OQ-1-163): construction is driven
 * directly because the global TrimStrings + ConvertEmptyStringsToNull pair
 * neutralises '' and ASCII-whitespace values before HTTP validation can see
 * one — the empty-string path is reachable only at this seam. Lives in an
 * analysed path so Larastan Level 8 covers it.
 *
 * @phpstan-require-extends TestCase
 */
trait AssertsFilterEmptyValueRejection
{
    /**
     * @param  class-string<Data>  $filtersClass
     */
    protected function assertEmptyFilterValueRejected(string $filtersClass, string $field, string $value): void
    {
        try {
            $filtersClass::validateAndCreate([$field => $value]);

            $this->fail("Expected ValidationException for {$field} => '{$value}'.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
