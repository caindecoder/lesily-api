<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Foundation\Auth\UseCases\Commands\ForgotPasswordCommand;

/**
 * Request a password-reset link (UC-1-008, forgot portion). The broker call is driven in by the
 * enumeration + happy cycles.
 */
class ForgotPasswordInteractor
{
    public function execute(ForgotPasswordCommand $command): void
    {
        // The password-broker call is added by the enumeration + happy cycles.
    }
}
