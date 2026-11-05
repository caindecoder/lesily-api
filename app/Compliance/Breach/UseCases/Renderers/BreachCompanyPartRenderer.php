<?php

namespace App\Compliance\Breach\UseCases\Renderers;

use App\Compliance\Breach\Entities\BreachIncidentRecord;
use App\Compliance\Breach\Entities\BreachIncidentCompany;

class BreachCompanyPartRenderer
{
    protected const string MOMENT_FORMAT = 'j M Y, H:i';

    /**
     * One company's own part of an incident. It reads one company row and never
     * the incident's company list, so no other company, company count or
     * incident-wide total can reach it.
     *
     * @return array<string, string>
     */
    public function render(BreachIncidentRecord $incident, BreachIncidentCompany $company): array
    {
        return [
            'discovered' => $incident->date_discovered->format(self::MOMENT_FORMAT).' UTC',
            'deadline' => $incident->deadline->format(self::MOMENT_FORMAT).' UTC',
            'description' => $incident->description,
            'consequences' => $incident->likely_consequences,
            'measures' => $incident->measures,
            'data_types' => implode(', ', array_map(fn (string $type): string => __("notifications.breach_data_types.{$type}"), $company->data_types)),
            'count' => $company->estimated_count === null
                ? __('notifications.breach_company_notice.count_not_known')
                : (string) $company->estimated_count,
        ];
    }
}
