<?php

namespace App\Foundation\Auth\UseCases\Commands;

/**
 * The current password a user submits to re-authenticate logging out all of their other
 * devices (UC-1-011). Shape-only validation; the password is *verified* against the stored
 * hash by RevokeAllSessionsInteractor (the credential check is the gatekeeper, not the
 * rules — mirrors DisableTwoFactorCommand).
 */
class LogoutAllCommand extends ConfirmPasswordCommand {}
