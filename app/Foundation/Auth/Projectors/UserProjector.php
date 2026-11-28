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
    public function onUserSignedUpAsOwner(UserSignedUpAsOwner $userSignedUpAsOwner): void
    {
        UserReadModel::query()->forceCreate([
            'id' => $userSignedUpAsOwner->userId,
            'name' => $userSignedUpAsOwner->name,
            'email' => $userSignedUpAsOwner->email,
            'password' => $userSignedUpAsOwner->passwordHash,
        ]);
    }

    public function onUserCreatedFromInvitation(UserCreatedFromInvitation $userCreatedFromInvitation): void
    {
        UserReadModel::query()->forceCreate([
            'id' => $userCreatedFromInvitation->userId,
            'name' => $userCreatedFromInvitation->name,
            'email' => $userCreatedFromInvitation->email,
            'password' => $userCreatedFromInvitation->passwordHash,
        ]);
    }

    public function onUserEmailVerified(UserEmailVerified $userEmailVerified): void
    {
        UserReadModel::query()
            ->whereKey($userEmailVerified->userId)
            ->update(['email_verified_at' => $userEmailVerified->verifiedAt]);
    }

    public function onUserPasswordChanged(UserPasswordChanged $userPasswordChanged): void
    {
        UserReadModel::query()
            ->whereKey($userPasswordChanged->userId)
            ->update(['password' => $userPasswordChanged->passwordHash]);
    }

    public function onUserPasswordReset(UserPasswordReset $userPasswordReset): void
    {
        UserReadModel::query()
            ->whereKey($userPasswordReset->userId)
            ->update(['password' => $userPasswordReset->passwordHash]);
    }

    public function onUserProfileUpdated(UserProfileUpdated $userProfileUpdated): void
    {
        UserReadModel::query()
            ->whereKey($userProfileUpdated->userId)
            ->update(['name' => $userProfileUpdated->name, 'locale' => $userProfileUpdated->locale]);
    }

    public function onUser2FAEnabled(User2FAEnabled $user2FAEnabled): void
    {
        UserReadModel::query()
            ->whereKey($user2FAEnabled->userId)
            ->update(['two_factor_confirmed_at' => $user2FAEnabled->confirmedAt]);
    }

    public function onUser2FADisabled(User2FADisabled $user2FADisabled): void
    {
        UserReadModel::query()
            ->whereKey($user2FADisabled->userId)
            ->update(['two_factor_confirmed_at' => null]);
    }

    public function onUserGoogleOAuthLinked(UserGoogleOAuthLinked $userGoogleOAuthLinked): void
    {
        UserReadModel::query()
            ->whereKey($userGoogleOAuthLinked->userId)
            ->update(['google_id' => $userGoogleOAuthLinked->googleSubjectId, 'google_unlinked_at' => null]);
    }

    public function onUserGoogleOAuthUnlinked(UserGoogleOAuthUnlinked $userGoogleOAuthUnlinked): void
    {
        UserReadModel::query()
            ->whereKey($userGoogleOAuthUnlinked->userId)
            ->update(['google_id' => null, 'google_unlinked_at' => $userGoogleOAuthUnlinked->unlinkedAt]);
    }
}
