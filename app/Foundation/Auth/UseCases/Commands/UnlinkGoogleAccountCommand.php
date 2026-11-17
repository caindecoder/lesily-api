<?php

namespace App\Foundation\Auth\UseCases\Commands;

/**
 * The password a user re-enters to unlink Google (UC-1-003 P2 / api-1-users DELETE /me/google);
 * it is verified by UnlinkGoogleAccountInteractor.
 */
class UnlinkGoogleAccountCommand extends ConfirmPasswordCommand {}
