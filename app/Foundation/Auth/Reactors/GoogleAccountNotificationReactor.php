<?php

namespace App\Foundation\Auth\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Foundation\Auth\Events\UserGoogleOAuthLinked;
use App\Foundation\Auth\Events\UserGoogleOAuthUnlinked;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;

class GoogleAccountNotificationReactor extends Reactor implements ShouldQueue
{
    public function onUserGoogleOAuthLinked(UserGoogleOAuthLinked $userGoogleOAuthLinked): void
    {
        $user = User::query()->findOrFail($userGoogleOAuthLinked->userId);

        $user->notify(new SystemNotification(
            NotificationType::GoogleAccountLinked,
            $user->notificationCompanyName(),
            null,
        ));
    }

    public function onUserGoogleOAuthUnlinked(UserGoogleOAuthUnlinked $userGoogleOAuthUnlinked): void
    {
        $user = User::query()->findOrFail($userGoogleOAuthUnlinked->userId);

        $user->notify(new SystemNotification(
            NotificationType::GoogleAccountUnlinked,
            $user->notificationCompanyName(),
            null,
        ));
    }
}
