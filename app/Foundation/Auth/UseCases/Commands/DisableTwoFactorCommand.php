<?php

namespace App\Foundation\Auth\UseCases\Commands;

/**
 * The current password a user submits to re-authenticate a sensitive 2FA change —
 * disabling 2FA (UC-1-007). Shape-only validation; the password is *verified* against
 * the stored hash by DisableTwoFactorInteractor (the credential check is the
 * gatekeeper, not the rules — mirrors ConfirmTwoFactorCommand).
 */
class DisableTwoFactorCommand extends ConfirmPasswordCommand {}
