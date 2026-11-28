<?php

namespace App\Foundation\AccessLog\Exceptions;

use Throwable;
use RuntimeException;
use App\Foundation\AccessLog\Values\AccessOutcome;

class AccessLogWriteFailedException extends RuntimeException
{
    public function __construct(private readonly AccessOutcome $accessOutcome, Throwable $throwable)
    {
        parent::__construct("Access log write failed: {$accessOutcome->accessEvent->value}.", previous: $throwable);
    }

    /**
     * The outcome that was not recorded, for the log record the report writes.
     *
     * @return array{event: string, reason: ?string, user_id: ?string, company_id: ?string}
     */
    public function context(): array
    {
        return [
            'event' => $this->accessOutcome->accessEvent->value,
            'reason' => $this->accessOutcome->accessEventReason?->value,
            'user_id' => $this->accessOutcome->userId,
            'company_id' => $this->accessOutcome->companyId,
        ];
    }
}
