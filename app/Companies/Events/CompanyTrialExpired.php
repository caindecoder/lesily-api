<?php

namespace App\Companies\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The 30-day trial elapsed without a subscription (SM-1-02 T3, Trial → TrialExpired).
 * A marker event — the company is the aggregate (companyId = aggregateRootUuid) and the
 * expiry instant is the stored event's recorded time; no payload is needed for the read
 * model, which only flips status to TrialExpired.
 */
class CompanyTrialExpired extends ShouldBeStored {}
