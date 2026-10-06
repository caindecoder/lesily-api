<?php

namespace App\Companies\Memberships\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Companies\Memberships\Entities\Invitation;
use App\Companies\Memberships\Events\UserJoinedCompany;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;

/**
 * Post-accept team welcome (D8/R8, session 105) — distinct from the
 * owner-signup WelcomeEmailReactor arm. Queued after-commit: the accept runs
 * inside a DB::transaction, so a rollback discards the welcome. Reading the
 * company name via the invitation row is safe — the company predates the
 * accept transaction (amendment N binds only same-tx sibling writes), and the
 * joiner's users row is projected before this reactor fires (genesis event
 * persists first). Needs no secret (D3/R10 killed the invite-mail reactor,
 * not this one). Auto-discovered.
 */
class TeamWelcomeReactor extends Reactor implements ShouldQueue
{
    public function onUserJoinedCompany(UserJoinedCompany $event): void
    {
        $company = Invitation::query()
            ->findOrFail($event->membershipId)
            ->company()
            ->firstOrFail();

        User::query()
            ->findOrFail($event->userId)
            ->notify(new SystemNotification(NotificationType::TeamWelcome, $company->name, $company->getKey()));
    }
}
