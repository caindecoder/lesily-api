<?php

namespace App\Compliance\Breach\Values;

use Carbon\CarbonImmutable;
use App\Compliance\Breach\Enums\BreachDataType;
use App\Compliance\Breach\Events\BreachRecorded;
use App\Compliance\Breach\Enums\BreachAmendmentField;
use App\Compliance\Breach\UseCases\Commands\AmendBreachCommand;
use App\Compliance\Breach\UseCases\Commands\AffectedCompanyData;

class BreachFacts
{
    /**
     * @param  array<string, array{dataTypes: list<string>, estimatedCount: ?int}>  $companies  keyed by company id
     */
    public function __construct(
        public readonly string $description,
        public readonly string $likelyConsequences,
        public readonly string $measures,
        public readonly CarbonImmutable $dateDiscovered,
        public readonly string $severity,
        public readonly array $companies,
    ) {}

    public static function recordedBy(BreachRecorded $event): self
    {
        $companies = [];

        foreach ($event->companies as $company) {
            $companies[$company['companyId']] = ['dataTypes' => $company['dataTypes'], 'estimatedCount' => $company['estimatedCount']];
        }

        return new self(
            description: $event->description,
            likelyConsequences: $event->likelyConsequences,
            measures: $event->measures,
            dateDiscovered: CarbonImmutable::instance($event->dateDiscovered),
            severity: $event->severity,
            companies: $companies,
        );
    }

    /**
     * @return list<array{field: string, companyId: ?string, old: mixed, new: mixed}>
     */
    public function changesTo(AmendBreachCommand $command): array
    {
        $changes = [
            ...$this->textChange(BreachAmendmentField::Description, $this->description, $command->description),
            ...$this->textChange(BreachAmendmentField::LikelyConsequences, $this->likelyConsequences, $command->likelyConsequences),
            ...$this->textChange(BreachAmendmentField::Measures, $this->measures, $command->measures),
        ];

        if ($command->dateDiscovered !== null && ! $command->dateDiscovered->equalTo($this->dateDiscovered)) {
            $changes[] = $this->change(BreachAmendmentField::DateDiscovered, null, [$this->dateDiscovered->toIso8601ZuluString(), $command->dateDiscovered->toIso8601ZuluString()]);
        }

        $changes = [...$changes, ...$this->textChange(BreachAmendmentField::Severity, $this->severity, $command->severity?->value)];

        return $command->companies === null ? $changes : [...$changes, ...$this->companyChanges($command->companies)];
    }

    /**
     * @return list<array{field: string, companyId: ?string, old: mixed, new: mixed}>
     */
    protected function textChange(BreachAmendmentField $field, string $current, ?string $sent): array
    {
        return $sent === null || $sent === $current ? [] : [$this->change($field, null, [$current, $sent])];
    }

    /**
     * @param  list<AffectedCompanyData>  $sent
     * @return list<array{field: string, companyId: ?string, old: mixed, new: mixed}>
     */
    protected function companyChanges(array $sent): array
    {
        $changes = [];
        $sentById = [];

        foreach ($sent as $company) {
            $sentById[$company->company->getKey()] = [
                'dataTypes' => array_map(fn (BreachDataType $type): string => $type->value, $company->dataTypes),
                'estimatedCount' => $company->estimatedCount,
            ];
        }

        foreach ($this->companies as $companyId => $current) {
            if (! isset($sentById[$companyId])) {
                $changes[] = $this->change(BreachAmendmentField::CompanyRemoved, $companyId, [$current, null]);

                continue;
            }

            $changes = [...$changes, ...$this->figureChanges($companyId, $current, $sentById[$companyId])];
        }

        foreach (array_diff_key($sentById, $this->companies) as $companyId => $figures) {
            $changes[] = $this->change(BreachAmendmentField::CompanyAdded, $companyId, [null, $figures]);
        }

        return $changes;
    }

    /**
     * @param  array{dataTypes: list<string>, estimatedCount: ?int}  $current
     * @param  array{dataTypes: list<string>, estimatedCount: ?int}  $sent
     * @return list<array{field: string, companyId: ?string, old: mixed, new: mixed}>
     */
    protected function figureChanges(string $companyId, array $current, array $sent): array
    {
        $changes = [];

        if ($this->sorted($current['dataTypes']) !== $this->sorted($sent['dataTypes'])) {
            $changes[] = $this->change(BreachAmendmentField::DataTypes, $companyId, [$current['dataTypes'], $sent['dataTypes']]);
        }

        if ($current['estimatedCount'] !== $sent['estimatedCount']) {
            $changes[] = $this->change(BreachAmendmentField::EstimatedCount, $companyId, [$current['estimatedCount'], $sent['estimatedCount']]);
        }

        return $changes;
    }

    /**
     * @param  array{mixed, mixed}  $oldAndNew
     * @return array{field: string, companyId: ?string, old: mixed, new: mixed}
     */
    protected function change(BreachAmendmentField $field, ?string $companyId, array $oldAndNew): array
    {
        return ['field' => $field->value, 'companyId' => $companyId, 'old' => $oldAndNew[0], 'new' => $oldAndNew[1]];
    }

    /**
     * @param  list<string>  $dataTypes
     * @return list<string>
     */
    protected function sorted(array $dataTypes): array
    {
        sort($dataTypes);

        return $dataTypes;
    }
}
