<?php

namespace App\Foundation\Auth\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Foundation\Auth\UseCases\PasswordVerifier;
use App\Foundation\Auth\Aggregates\User as UserAggregate;
use App\Foundation\Auth\Exceptions\GoogleNotLinkedException;
use App\Foundation\Auth\UseCases\Commands\UnlinkGoogleAccountCommand;

class UnlinkGoogleAccountInteractor
{
    public function __construct(private PasswordVerifier $passwordVerifier) {}

    public function execute(User $user, UnlinkGoogleAccountCommand $command, string $currentTokenId): void
    {
        if ($user->google_id === null) {
            throw new GoogleNotLinkedException;
        }

        $this->passwordVerifier->verify($user, $command->password);

        DB::transaction(function () use ($user): void {
            UserAggregate::retrieve($user->getKey())->unlinkGoogleOAuth()->persist();
        });
    }
}
