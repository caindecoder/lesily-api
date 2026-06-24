<?php

namespace App\Foundation\Auth\UseCases\Results;

/**
 * The sealed result of LogInInteractor: either a 2FA ChallengeRequired (no token yet) or a
 * SessionIssued (token minted). The controller maps the concrete subtype to its HTTP status
 * (202 vs 200) — the 2FA decision itself lives in the interactor, not the delivery layer
 * (session 090 token-lifecycle refactor).
 *
 * NOTE: LogInInteractor::execute() declares the concrete union `ChallengeRequired|SessionIssued`
 * (NOT this base) as its return type — that 2-member union is what lets the controller narrow to
 * SessionIssued after its `instanceof ChallengeRequired` guard. Do not widen that return type to
 * LoginOutcome, or the exhaustive narrowing (and PHPStan) breaks.
 */
abstract class LoginOutcome {}
