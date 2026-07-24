<?php

namespace App\Companies\OwnershipTransfers\UseCases\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A verification artefact is a CLOSED object: exactly a type, a reference and a
 * capturedAt (ERD-1-05 §3). Artefacts persist verbatim into `stored_events`, so
 * an unpoliced extra key is a route for document bytes to reach the immutable
 * event store — which the metadata-only contract forbids (OQ-1-045 / D4:
 * references and capture times, never the documents).
 *
 * A named rule rather than an inline closure: Laravel has no built-in
 * "no unknown keys" rule, the intent deserves a name, and the `$attribute`
 * parameter is contract-mandated here instead of dead weight in a closure
 * signature.
 */
class ArtefactCarriesOnlyMetadata implements ValidationRule
{
    private const ALLOWED_KEYS = ['type', 'reference', 'capturedAt'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Laravel does not bail per attribute, so this runs even when the
        // sibling `array` rule has already failed — a scalar element must not
        // reach array_keys().
        if (! is_array($value)) {
            return;
        }

        if (array_diff(array_keys($value), self::ALLOWED_KEYS) !== []) {
            $fail('Each verification artefact may carry only a type, a reference and a capturedAt.');
        }
    }
}
