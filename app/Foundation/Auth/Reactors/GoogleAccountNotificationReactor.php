<?php

namespace App\Foundation\Auth\Reactors;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Foundation\Auth\Events\UserGoogleOAuthLinked;
use App\Foundation\Notifications\Enums\NotificationType;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Foundation\Notifications\Notifications\SystemNotification;

class GoogleAccountNotificationReactor extends Reactor implements ShouldQueue
{
    public function onUserGoogleOAuthLinked(UserGoogleOAuthLinked $event): void
    {
        $user = User::query()->findOrFail($event->userId);

        $user->notify(new SystemNotification(
            NotificationType::GoogleAccountLinked,
            $user->notificationCompanyName(),
            null,
        ));
    }
}
