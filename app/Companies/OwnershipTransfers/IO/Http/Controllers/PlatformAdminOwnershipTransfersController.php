<?php

namespace App\Companies\OwnershipTransfers\IO\Http\Controllers;

use RuntimeException;

/**
 * The platform-admin cross-tenant ownership-transfer reads (UC-1-127) — behind
 * auth:sanctum + platform.admin (B2 INTERIM allowlist; 404 no-info-leak),
 * deliberately NO auth.company. The delivery layer translates and presents
 * only; the unscoped reads and their audit_trail rows live in the interactors
 * (E07 — the UC-1-122 precedent). Tasks 4/5/8 extend this class with the
 * verify / reject / execute transitions.
 */
class PlatformAdminOwnershipTransfersController
{
    public function index(): never
    {
        throw new RuntimeException('Not implemented');
    }
}
