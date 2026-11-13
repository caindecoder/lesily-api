<?php

namespace App\Companies\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanySettings;
use App\Companies\Aggregates\SettingsBaseline;
use App\Companies\UseCases\Audit\CompanyAudit;
use App\Companies\UseCases\Audit\CompanyChange;
use App\Foundation\AuditTrail\Values\FieldChanges;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Companies\Aggregates\Company as CompanyAggregate;
use App\Companies\UseCases\Commands\UpdateCompanyCommand;

/**
 * Update a company's editable details (UC-1-033). The delivery gates run upstream
 * (auth.company membership + permission:companies.update) and the command whitelist
 * already refused immutable/unknown fields. The settings row is read under its lock
 * so the aggregate decides against the company's current own values (UC-1-058).
 */
class UpdateCompanyDetailsInteractor
{
    public function __construct(protected readonly CompanyAudit $audit) {}

    public function execute(UpdateCompanyCommand $command, User $actor, RequestOrigin $origin): Company
    {
        $company = $command->company;
        /** @var string $actorId users are UUID-keyed (HasUuids) */
        $actorId = $actor->getKey();

        DB::transaction(function () use ($company, $command, $actor, $actorId, $origin): void {
            $settings = CompanySettings::query()
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->sole();
            $baseline = SettingsBaseline::of($settings);
            $company->refresh();
            $changes = $this->changesOf($command, $settings, $baseline);

            CompanyAggregate::retrieve($company->getKey())
                ->updateDetails($command, $baseline, $actorId)
                ->persist();

            $company->refresh();

            foreach ($changes as $change) {
                $this->audit->changed($change, $actor, $origin);
            }
        });

        return $company;
    }

    /**
     * Each kind of change with the values that applied before and after it.
     *
     * @return list<CompanyChange>
     */
    protected function changesOf(UpdateCompanyCommand $command, CompanySettings $settings, SettingsBaseline $baseline): array
    {
        $company = $command->company;
        $sent = $command->settingsSent();
        $ownValues = $baseline->ownValueChanges($sent);
        $changes = [
            CompanyChange::detailsOf($company, FieldChanges::between(
                ['name' => $company->name],
                $command->name === null ? [] : ['name' => $command->name],
            )),
            CompanyChange::settingsOf($company, FieldChanges::between(
                $this->valuesThatApplied($settings, array_keys($ownValues)),
                $ownValues,
            )),
        ];

        foreach ($baseline->settingsFollowingAgain($sent) as $setting) {
            $changes[] = CompanyChange::resetOf($company, $settings, $setting);
        }

        return $changes;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    protected function valuesThatApplied(CompanySettings $settings, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $values[$column] = $settings->valueThatApplies($column);
        }

        return $values;
    }
}
