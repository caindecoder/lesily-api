<?php

namespace App\Foundation\Auth\UseCases\Commands;

/**
 * The current password a user submits to re-authenticate a sensitive 2FA change —
 * regenerating their recovery codes (UC-1-137). Shape-only validation; the password is
 * *verified* against the stored hash by RegenerateRecoveryCodesInteractor (the credential
 * check is the gatekeeper, not the rules — mirrors DisableTwoFactorCommand).
 */
class RegenerateRecoveryCodesCommand extends ConfirmPasswordCommand {}
