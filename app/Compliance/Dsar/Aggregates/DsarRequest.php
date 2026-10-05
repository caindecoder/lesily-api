<?php

namespace App\Compliance\Dsar\Aggregates;

use Carbon\CarbonImmutable;
use App\Foundation\EventStore\AggregateRoot;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Compliance\Dsar\Events\DsarRequestRejected;
use App\Compliance\Dsar\Events\DsarRequestCancelled;
use App\Compliance\Dsar\Events\DsarRequestFulfilled;
use App\Compliance\Dsar\Events\DsarRequestSubmitted;
use App\Compliance\Dsar\Exceptions\DsarRequesterMismatchException;
use App\Compliance\Dsar\UseCases\Commands\CancelDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\FulfilDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\RejectDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;
use App\Compliance\Dsar\Exceptions\DsarRequestNotInProgressException;

class DsarRequest extends AggregateRoot
{
    private const int SLA_DAYS = 30;

    private const string REQUEST_TYPE_ACCESS = 'access';

    private const int DOWNLOAD_DAYS = 7;

    protected ?DsarRequestStatus $status = null;

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

        if ($this->status !== DsarRequestStatus::InProgress) {
            throw new DsarRequestNotInProgressException;
        }

        return $this->recordThat(new DsarRequestCancelled(
            cancelledAt: CarbonImmutable::now(),
            cancelledByUserId: $command->cancelledByUserId,
        ));
    }

    public function fulfil(FulfilDsarRequestCommand $command): static
    {
        if ($this->status !== DsarRequestStatus::InProgress) {
            throw new DsarRequestNotInProgressException;
        }

        $completedAt = CarbonImmutable::now();

        return $this->recordThat(new DsarRequestFulfilled(
            completedAt: $completedAt,
            filePath: $command->filePath,
            downloadExpiresAt: $completedAt->addDays(self::DOWNLOAD_DAYS),
        ));
    }

    public function reject(RejectDsarRequestCommand $command): static
    {
        if ($this->status !== DsarRequestStatus::InProgress) {
            throw new DsarRequestNotInProgressException;
        }

        return $this->recordThat(new DsarRequestRejected(
            rejectedAt: CarbonImmutable::now(),
            reason: $command->reason,
        ));
    }

    protected function applyDsarRequestSubmitted(DsarRequestSubmitted $event): void
    {
        $this->status = DsarRequestStatus::InProgress;
        $this->requestedByUserId = $event->requestedByUserId;
    }

    protected function applyDsarRequestFulfilled(DsarRequestFulfilled $event): void
    {
        $this->status = DsarRequestStatus::Fulfilled;
    }

    protected function applyDsarRequestRejected(DsarRequestRejected $event): void
    {
        $this->status = DsarRequestStatus::Rejected;
    }

    protected function applyDsarRequestCancelled(DsarRequestCancelled $event): void
    {
        $this->status = DsarRequestStatus::Cancelled;
    }
}
