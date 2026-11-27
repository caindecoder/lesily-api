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
    public function render(BreachIncidentRecord $breachIncident, BreachIncidentCompany $breachIncidentCompany): array
    {
        return [
            'discovered' => $breachIncident->date_discovered->format(self::MOMENT_FORMAT).' UTC',
            'deadline' => $breachIncident->deadline->format(self::MOMENT_FORMAT).' UTC',
            'description' => $breachIncident->description,
            'consequences' => $breachIncident->likely_consequences,
            'measures' => $breachIncident->measures,
            'data_types' => implode(', ', array_map(fn (string $type): string => __("notifications.breach_data_types.{$type}"), $breachIncidentCompany->data_types)),
            'count' => $breachIncidentCompany->estimated_count === null
                ? __('notifications.breach_company_notice.count_not_known')
                : (string) $breachIncidentCompany->estimated_count,
        ];
    }
}
