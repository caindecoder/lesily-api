<?php

namespace App\Foundation\Auth\Projectors;

use App\Models\User as UserReadModel;
use App\Foundation\Auth\Events\User2FAEnabled;
use App\Foundation\Auth\Events\User2FADisabled;
use App\Foundation\Auth\Events\UserEmailVerified;
use App\Foundation\Auth\Events\UserPasswordReset;
use App\Foundation\Auth\Events\UserProfileUpdated;
use App\Foundation\Auth\Events\UserPasswordChanged;
use App\Foundation\Auth\Events\UserSignedUpAsOwner;
use App\Foundation\Auth\Events\UserGoogleOAuthLinked;
use App\Foundation\Auth\Events\UserGoogleOAuthUnlinked;
use App\Foundation\Auth\Events\UserCreatedFromInvitation;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Mirrors the User identity stream into the `users` read model (ERD-1-01). The
 * event carries the already-hashed password; `locale` keeps its column default.
 * forceCreate: the projector supplies the stream's uuid as the PK without the
 * auth model carrying `id` in its fillable surface (future profile-update fills
 * must never be able to touch the key).
 *
 * 2FA marker (Design-Y): onUser2FAEnabled sets only the non-secret
 * two_factor_confirmed_at flag — the encrypted secret + hashed recovery codes are
 * written to the users row directly by the confirm interactor, never projected.
 */
class UserProjector extends Projector
{
    public function onUserSignedUpAsOwner(UserSignedUpAsOwner $event): void
    {
        UserReadModel::query()->forceCreate([
            'id' => $event->userId,
            'name' => $event->name,
            'email' => $event->email,
            'password' => $event->passwordHash,
        ]);
    }

    public function onUserCreatedFromInvitation(UserCreatedFromInvitation $event): void
    {
        UserReadModel::query()->forceCreate([
            'id' => $event->userId,
            'name' => $event->name,
            'email' => $event->email,
            'password' => $event->passwordHash,
        ]);
    }

    public function onUserEmailVerified(UserEmailVerified $event): void
    {
        UserReadModel::query()
            ->whereKey($event->userId)
            ->update(['email_verified_at' => $event->verifiedAt]);
    }

    public function onUserPasswordChanged(UserPasswordChanged $event): void
    {
        UserReadModel::query()
            ->whereKey($event->userId)
            ->update(['password' => $event->passwordHash]);
    }

    public function onUserPasswordReset(UserPasswordReset $event): void
    {
        UserReadModel::query()
            ->whereKey($event->userId)
            ->update(['password' => $event->passwordHash]);
    }

    public function onUserProfileUpdated(UserProfileUpdated $event): void
    {
        UserReadModel::query()
            ->whereKey($event->userId)
            ->update(['name' => $event->name, 'locale' => $event->locale]);
    }

    public function onUser2FAEnabled(User2FAEnabled $event): void
    {
        UserReadModel::query()
            ->whereKey($event->userId)
            ->update(['two_factor_confirmed_at' => $event->confirmedAt]);
    }

    public function onUser2FADisabled(User2FADisabled $event): void
    {
        UserReadModel::query()
            ->whereKey($event->userId)
            ->update(['two_factor_confirmed_at' => null]);
    }

    public function onUserGoogleOAuthLinked(UserGoogleOAuthLinked $event): void
    {
        UserReadModel::query()
            ->whereKey($event->userId)
            ->update(['google_id' => $event->googleSubjectId]);
    }

    public function onUserGoogleOAuthUnlinked(UserGoogleOAuthUnlinked $event): void
    {
        UserReadModel::query()
            ->whereKey($event->userId)
            ->update(['google_id' => null, 'google_unlinked_at' => $event->unlinkedAt]);
    }
}
