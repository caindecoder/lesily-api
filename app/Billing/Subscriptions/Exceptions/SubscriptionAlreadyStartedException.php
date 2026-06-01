<?php

namespace App\Billing\Subscriptions\Exceptions;

use DomainException;

class SubscriptionAlreadyStartedException extends DomainException
{
    public static function withId(string $subscriptionId): self
    {
        return new self("Subscription {$subscriptionId} has already started a trial.");
    }
}
