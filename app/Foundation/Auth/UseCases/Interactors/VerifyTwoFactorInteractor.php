<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use RuntimeException;
use App\Foundation\Auth\UseCases\Commands\VerifyTwoFactorCommand;

class VerifyTwoFactorInteractor
{
    public function execute(VerifyTwoFactorCommand $command): User
    {
        throw new RuntimeException('Not implemented');
    }
}
