<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use App\Foundation\Auth\UseCases\Google\GoogleIdTokenVerifier;
use App\Foundation\Auth\UseCases\Commands\LinkGoogleAccountCommand;

class LinkGoogleAccountInteractor
{
    public function __construct(
        protected GoogleIdTokenVerifier $verifier,
    ) {}

    public function execute(User $user, LinkGoogleAccountCommand $command): void
    {
        $this->verifier->verify($command->idToken);
    }
}
