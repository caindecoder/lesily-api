<?php

namespace App\Compliance\Breach\Reactors;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Compliance\Breach\Events\BreachCompanyNotified;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class BreachNotificationReactor extends Reactor implements ShouldQueue
{
    public function onBreachCompanyNotified(BreachCompanyNotified $event): void {}
}
