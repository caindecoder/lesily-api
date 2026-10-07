<?php

namespace App\Compliance\Dsar\UseCases\Reports;

class DsarReportCover
{
    public function __construct(
        public readonly string $subjectName,
        public readonly string $subjectEmail,
        public readonly string $companyName,
        public readonly string $reference,
        public readonly string $requestedAt,
        public readonly string $generatedAt,
    ) {}
}
