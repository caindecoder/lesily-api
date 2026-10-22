<?php

namespace App\Foundation\Auth\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Sends the "verify your email" link when an owner signs up (UC-1-001 alt-6a).
 *
 * Queued (ShouldQueue) so the send is a true after-commit side effect: owner
 * signup runs inside the fused register DB::transaction (RegisterOwnerInteractor,
 * Pattern A), and the email must NOT leave the building until that transaction
 * commits — a mid-transaction rollback (e.g. an unsupported country) must send
 * nothing. With after_commit on the queue connection the deferred dispatch is
 * discarded on rollback; an inline (synchronous) reactor would leak a
 * verification email for an account that never existed.
 *
 * Listens to UserSignedUpAsOwner — the slice's genesis event — rather than
 * cd-auth's UserSignedUp, which belongs to the later non-owner signup path.
 * The user model sends EmailVerificationLinkNotification (Laravel's VerifyEmail,
 * queued), which builds the signed `verification.verify` URL; nothing is gated on
 * verified status.
 */
class EmailVerificationReactor extends Reactor implements ShouldQueue
{
    public function onUserSignedUpAsOwner(UserSignedUpAsOwner $event): void
    {
        User::query()->findOrFail($event->userId)->sendEmailVerificationNotification();
    }
}
