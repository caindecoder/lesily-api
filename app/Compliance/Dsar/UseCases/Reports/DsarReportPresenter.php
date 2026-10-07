<?php

namespace App\Compliance\Dsar\UseCases\Reports;

use Carbon\CarbonInterface;
use App\Companies\Entities\Company;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

class DsarReportPresenter
{
    /**
     * The report of $request's export — its sections as collected, every
     * value written for a person to read.
     *
     * @param  array<string, array<int|string, mixed>>  $sections
     */
    public function present(DsarRequestRecord $request, array $sections): DsarReport
    {
        return new DsarReport($this->coverOf($request), array_map(
            fn (string $key): DsarReportSection => new DsarReportSection(__("dsar.sections.{$key}.title")),
            array_keys($sections),
        ));
    }

    private function coverOf(DsarRequestRecord $request): DsarReportCover
    {
        return new DsarReportCover(
            subjectName: $request->subject->name,
            subjectEmail: $request->subject->email,
            companyName: Company::query()->findOrFail($request->company_id)->name,
            reference: $request->getKey(),
            requestedAt: $this->readableTime($request->requested_at),
            generatedAt: $this->readableTime(now()),
        );
    }

    private function readableTime(CarbonInterface $time): string
    {
        return $time->utc()->format('j M Y, H:i').' UTC';
    }
}
