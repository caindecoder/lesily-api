<?php

namespace App\Companies\Memberships\IO\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Attributes\Description;
use App\Companies\Memberships\UseCases\Interactors\ExpirePendingInvitationsInteractor;

#[Signature('invitations:expire')]
#[Description('Expire pending invitations whose window has elapsed (runs daily).')]
class ExpireInvitations extends Command
{
    public function handle(ExpirePendingInvitationsInteractor $interactor): int
    {
        $expired = $interactor->execute(CarbonImmutable::now());

        $this->info("{$expired} invitation(s) expired.");

        return self::SUCCESS;
    }
}
