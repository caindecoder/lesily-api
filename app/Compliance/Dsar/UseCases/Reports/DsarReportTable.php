<?php

namespace App\Compliance\Dsar\UseCases\Reports;

/**
 * One table of a report section: with column headings, a list of entries; with none, one
 * entry's fields as label / value rows. No rows means nothing was recorded.
 */
class DsarReportTable
{
    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function __construct(
        public readonly ?string $caption,
        public readonly array $columns,
        public readonly array $rows,
    ) {}
}
