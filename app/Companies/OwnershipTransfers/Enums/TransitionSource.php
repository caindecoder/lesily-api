<?php

namespace App\Companies\OwnershipTransfers\Enums;

/**
 * WHICH window a paired SM-1-05 transition ended in. Three events share one
 * discriminator idiom — Cancelled (T6/T7), Rejected (T3/T5) and Expired (T8/T9)
 * — and this enum is where the BUILT literals are spelled.
 *
 * Not yet the only place: the expiry pair still lives as a bare literal in the
 * factory's `expired()` state (below), and the reject test class pins its own
 * `rejection_source` values as strings on purpose — an assertion that reads its
 * expected value from this enum would move whenever this enum moved, and could
 * not fail. The withdraw class pins its two the same way, for the same reason.
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

    // T9's `verified-stage` is deliberately still absent: row 11 of the Task-7
    // table is its first producer, and shipping it here would add a member no
    // code path can emit (the PR #114 unconsumed-artifact cut, which is also why
    // T8's case arrives now rather than at s154 alongside the withdraw pair).
}
