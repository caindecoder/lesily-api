<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use DateTimeInterface;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class NamedDates
{
    public function schedule(
        CarbonImmutable $filedAt,
        CarbonImmutable $startsOn,
        CarbonInterface $dueDate,
        DateTimeInterface $notifyDeadline,
        CarbonImmutable $validUntil,
    ): void {
        //
    }
}
