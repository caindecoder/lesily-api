<?php

namespace App\Foundation\Auth\UseCases\Results;

/**
 * The sealed result of LogInInteractor: either a 2FA ChallengeRequired (no token yet) or a
 * SessionIssued (token minted). The controller maps the concrete subtype to its HTTP status
 * (202 vs 200) — the 2FA decision itself lives in the interactor, not the delivery layer
 * (session 090 token-lifecycle refactor).
 */
abstract class LoginOutcome {}
