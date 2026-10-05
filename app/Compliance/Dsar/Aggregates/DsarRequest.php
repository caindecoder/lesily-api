<?php

namespace App\Compliance\Dsar\Aggregates;

use Carbon\CarbonImmutable;
use App\Foundation\EventStore\AggregateRoot;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;
use App\Compliance\Dsar\Exceptions\DsarRequesterMismatchException;
use App\Compliance\Dsar\UseCases\Commands\CancelDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;

class DsarRequest extends AggregateRoot
{
    private const int SLA_DAYS = 30;

    private const string REQUEST_TYPE_ACCESS = 'access';

    protected ?string $requestedByUserId = null;

    public function submit(SubmitDsarRequestCommand $command, string $requestedByRole): static
    {
        $requestedAt = CarbonImmutable::now();

        return $this->forCompany($command->companyId)
            ->recordThat(new DsarRequestSubmitted(
                requestId: $this->uuid(),
                companyId: $command->companyId,
                subjectUserId: $command->subjectUserId(),
                requestedByUserId: $command->requestedByUserId,
                requestedByRole: $requestedByRole,
                requestType: self::REQUEST_TYPE_ACCESS,
                requestedAt: $requestedAt,
                slaDeadline: $requestedAt->addDays(self::SLA_DAYS),
            ));
    }

    public function cancel(CancelDsarRequestCommand $command): static
    {
        if ($command->cancelledByUserId !== $this->requestedByUserId) {
            throw new DsarRequesterMismatchException;
        }

        return $this;
    }

    protected function applyDsarRequestSubmitted(DsarRequestSubmitted $event): void
    {
        $this->requestedByUserId = $event->requestedByUserId;
    }
}
