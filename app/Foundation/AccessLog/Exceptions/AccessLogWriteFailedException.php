<?php

namespace App\Foundation\AccessLog\Exceptions;

use Throwable;
use RuntimeException;
use App\Foundation\AccessLog\Values\AccessOutcome;

class AccessLogWriteFailedException extends RuntimeException
{
    public function __construct(private readonly AccessOutcome $outcome, Throwable $previous)
    {
        parent::__construct("Access log write failed: {$outcome->event->value}.", previous: $previous);
    }

    /**
     * The outcome that was not recorded, for the log record the report writes.
     *
     * @return array{event: string, reason: ?string, user_id: ?string, company_id: ?string}
     */
    public function context(): array
    {
        return [
            'event' => $this->outcome->event->value,
            'reason' => $this->outcome->reason?->value,
            'user_id' => $this->outcome->userId,
            'company_id' => $this->outcome->companyId,
        ];
    }
}
