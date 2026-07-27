<?php

namespace App\Companies\OwnershipTransfers\Enums;

/**
 * WHICH window a paired SM-1-05 transition ended in. Three events share one
 * discriminator idiom — Cancelled (T6/T7), Rejected (T3/T5) and Expired (T8/T9)
 * — and this enum is the single place their literals are spelled.
 *
 * The backed values are the canon from ERD-1-05 §3: hyphenated, matching the
 * built `void_source` (`dispute-pre-payment` | `admin-correction`) and
 * `InvoicePaid.source` (`first-attempt`) precedents.
 *
 * Deliberately NOT used as an event-payload TYPE (ruling J6). `stored_events` is
 * append-only, so retyping a merged event's property is an event-versioning
 * problem (OQ-1-108) rather than a refactor, and this module already has a scar
 * from a payload field the serializer could not resolve — the event wrote but
 * could never be replayed (s149 C3). No built event anywhere carries an enum.
 * Aggregates derive a case and record `->value`; the same posture
 * `RejectionReasonCode` takes, which lives on the Command boundary and reaches
 * the payload as a plain string.
 */
enum TransitionSource: string
{
    case PreVerification = 'pre-verification';
    case PostVerification = 'post-verification';
}
