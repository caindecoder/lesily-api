<?php

namespace App\Compliance\Breach\Reactors;

use Illuminate\Support\Facades\Mail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use App\Compliance\Breach\Mail\BreachRegulatorNoticeMail;
use App\Compliance\Breach\Events\BreachFiledWithRegulator;

class BreachRegulatorFilingReactor extends Reactor implements ShouldQueue
{
    public function onBreachFiledWithRegulator(BreachFiledWithRegulator $event): void
    {
        Mail::to($event->regulatorAddress)->send(new BreachRegulatorNoticeMail($event->companyName, $event->part));
    }
}
