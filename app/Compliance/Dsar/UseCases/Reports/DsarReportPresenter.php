<?php

namespace App\Compliance\Dsar\UseCases\Reports;

use Carbon\CarbonInterface;
use App\Companies\Entities\Company;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

class DsarReportPresenter
{
    /**
     * The report of $request's export, every value written for a person to read.
     */
    public function present(DsarRequestRecord $request): DsarReport
    {
        return new DsarReport(new DsarReportCover(
            subjectName: $request->subject->name,
            subjectEmail: $request->subject->email,
            companyName: Company::query()->findOrFail($request->company_id)->name,
            reference: $request->getKey(),
            requestedAt: $this->readableTime($request->requested_at),
            generatedAt: $this->readableTime(now()),
        ));
    }

    private function readableTime(CarbonInterface $time): string
    {
        return $time->utc()->format('j M Y, H:i').' UTC';
    }
}
