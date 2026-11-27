<?php

namespace App\Compliance\Dsar\Aggregates;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Foundation\EventStore\AggregateRoot;
use App\Compliance\Dsar\Events\DsarSlaBreached;
use App\Compliance\Dsar\Enums\DsarRequestStatus;
use App\Compliance\Dsar\Events\DsarDownloadExpired;
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

    protected ?DsarRequestStatus $dsarRequestStatus = null;

    protected ?string $requestedByUserId = null;

    /** The 30-day deadline while the request is open; closing it clears it. */
    protected ?CarbonInterface $openDeadline = null;

    protected bool $slaBreached = false;

    protected ?CarbonInterface $downloadExpiresAt = null;

    protected bool $downloadExpired = false;

    public function submit(SubmitDsarRequestCommand $submitDsarRequestCommand, string $requestedByRole): static
    {
        $requestedAt = CarbonImmutable::now();

        return $this->forCompany($submitDsarRequestCommand->company->getKey())
            ->recordThat(new DsarRequestSubmitted(
                requestId: $this->uuid(),
                companyId: $submitDsarRequestCommand->company->getKey(),
                subjectUserId: $submitDsarRequestCommand->resolvedSubjectUserId(),
                requestedByUserId: $submitDsarRequestCommand->requestedBy->getKey(),
                requestedByRole: $requestedByRole,
                requestType: self::REQUEST_TYPE_ACCESS,
                requestedAt: $requestedAt,
                slaDeadline: $requestedAt->addDays(self::SLA_DAYS),
            ));
    }

    public function cancel(CancelDsarRequestCommand $cancelDsarRequestCommand): static
    {
        /** @var string $cancelledById users are UUID-keyed (HasUuids) */
        $cancelledById = $cancelDsarRequestCommand->cancelledBy->getKey();

        if ($cancelledById !== $this->requestedByUserId) {
            throw new DsarRequesterMismatchException;
        }

        if ($this->dsarRequestStatus !== DsarRequestStatus::InProgress) {
            throw new DsarRequestNotInProgressException;
        }

        return $this->recordThat(new DsarRequestCancelled(
            cancelledAt: CarbonImmutable::now(),
            cancelledByUserId: $cancelledById,
        ));
    }

    public function fulfil(FulfilDsarRequestCommand $fulfilDsarRequestCommand): static
    {
        if ($this->dsarRequestStatus !== DsarRequestStatus::InProgress) {
            throw new DsarRequestNotInProgressException;
        }

        $completedAt = CarbonImmutable::now();

        return $this->recordThat(new DsarRequestFulfilled(
            completedAt: $completedAt,
            filePath: $fulfilDsarRequestCommand->filePath,
            downloadExpiresAt: $completedAt->addDays(self::DOWNLOAD_DAYS),
        ));
    }

    public function reject(RejectDsarRequestCommand $rejectDsarRequestCommand): static
    {
        if ($this->dsarRequestStatus !== DsarRequestStatus::InProgress) {
            throw new DsarRequestNotInProgressException;
        }

        return $this->recordThat(new DsarRequestRejected(
            rejectedAt: CarbonImmutable::now(),
            reason: $rejectDsarRequestCommand->reason,
        ));
    }

    /**
     * Closes a fulfilled export's download once its window has closed by
     * $expiredAt; any other request, or one already expired, is left as it is.
     * Only fulfilment opens a download window.
     */
    public function expireDownload(CarbonInterface $expiredAt): static
    {
        if ($this->downloadExpiresAt === null || $this->downloadExpired || $expiredAt->lessThan($this->downloadExpiresAt)) {
            return $this;
        }

        return $this->recordThat(new DsarDownloadExpired(expiredAt: $expiredAt));
    }

    /**
     * Records that an open request missed its 30-day deadline, found by $breachedAt;
     * a closed request, one not yet due or one already flagged is left as
     * it is.
     */
    public function flagSlaBreach(CarbonInterface $breachedAt): static
    {
        if ($this->openDeadline === null || $this->slaBreached || $breachedAt->lessThan($this->openDeadline)) {
            return $this;
        }

        return $this->recordThat(new DsarSlaBreached(breachedAt: $breachedAt));
    }

    protected function applyDsarRequestSubmitted(DsarRequestSubmitted $dsarRequestSubmitted): void
    {
        $this->dsarRequestStatus = DsarRequestStatus::InProgress;
        $this->requestedByUserId = $dsarRequestSubmitted->requestedByUserId;
        $this->openDeadline = $dsarRequestSubmitted->slaDeadline;
    }

    protected function applyDsarSlaBreached(DsarSlaBreached $dsarSlaBreached): void
    {
        $this->slaBreached = true;
    }

    protected function applyDsarRequestFulfilled(DsarRequestFulfilled $dsarRequestFulfilled): void
    {
        $this->dsarRequestStatus = DsarRequestStatus::Fulfilled;
        $this->openDeadline = null;
        $this->downloadExpiresAt = $dsarRequestFulfilled->downloadExpiresAt;
    }

    protected function applyDsarDownloadExpired(DsarDownloadExpired $dsarDownloadExpired): void
    {
        $this->downloadExpired = true;
    }

    protected function applyDsarRequestRejected(DsarRequestRejected $dsarRequestRejected): void
    {
        $this->dsarRequestStatus = DsarRequestStatus::Rejected;
        $this->openDeadline = null;
    }

    protected function applyDsarRequestCancelled(DsarRequestCancelled $dsarRequestCancelled): void
    {
        $this->dsarRequestStatus = DsarRequestStatus::Cancelled;
        $this->openDeadline = null;
    }
}
