<?php

namespace App\Companies\OwnershipTransfers\Enums;

/**
 * WHICH window a paired SM-1-05 transition ended in. Three events share one
 * discriminator idiom — Cancelled (T6/T7), Rejected (T3/T5) and Expired (T8/T9)
 * — and this enum is where the BUILT literals are spelled.
 *
 * Now the only place in PRODUCTION: the factory's `expired()` state migrated at
 * Task 7, once T8 existed to produce the case, completing the set the other two
 * literals joined at s152.
 *
 * TESTS deliberately still pin strings. An assertion that read its expected
 * value from this enum would move whenever this enum moved, and so could not
 * fail — s155 L1 proved that exactly, when respelling `PostVerification` as
 * `post_verification` left 91 tests green. The reject, withdraw and expiry
 * classes all pin literals for this reason; ERD-1-05 §3 is the canon they answer
 * to, not this file.
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
    /** T3 reject, and T6 withdraw — the request had not been verified yet. */
    case PreVerification = 'pre-verification';

    /** T7 withdraw — verified, but the requester pulled it before execution. */
    case PostVerification = 'post-verification';

    /** T5 reject — the admin found the problem at execution time. */
    case ExecutionTime = 'execution-time';

    /** T8 expiry — 30 days passed and nobody verified the request. */
    case RequestedStage = 'requested-stage';

    /** T9 expiry — verified, but 14 days passed and nobody executed it. */
    case VerifiedStage = 'verified-stage';
}
