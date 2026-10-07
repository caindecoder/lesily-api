<?php

namespace App\Compliance\Dsar\UseCases\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Companies\Entities\Company;
use Illuminate\Support\Facades\Lang;
use App\Foundation\RolesPermissions\Entities\Role;
use App\Compliance\Dsar\Entities\DsarRequestRecord;

class DsarReportPresenter
{
    /** @var array<string, string>|null */
    private ?array $roleNames = null;

    /**
     * The report of $request's export — its sections as collected, every
     * value written for a person to read.
     *
     * @param  array<string, array<int|string, mixed>>  $sections
     */
    public function present(DsarRequestRecord $request, array $sections): DsarReport
    {
        return new DsarReport($this->coverOf($request), array_map(
            fn (string $key, array $data): DsarReportSection => new DsarReportSection(
                __("dsar.sections.{$key}.title"),
                $this->tablesOf($key, $data, null),
            ),
            array_keys($sections),
            $sections,
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

    /**
     * A list of flat entries is one table; a list whose entries hold lists
     * of their own becomes each entry's tables; a single entry is its fields
     * as label / value rows, then a table per list it holds.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int, DsarReportTable>
     */
    private function tablesOf(string $section, array $data, ?string $caption): array
    {
        if (! array_is_list($data)) {
            /** @var array<string, mixed> $data */
            return $this->entryTables($section, $data, $caption);
        }

        /** @var array<int, array<string, mixed>> $data */
        if ($data !== [] && ! $this->isFlat($data[0])) {
            return array_merge(...array_map(fn (array $entry): array => $this->entryTables($section, $entry, $caption), $data));
        }

        return [new DsarReportTable(
            $caption,
            array_map(fn (string $field): string => $this->label($section, $field), array_keys($data[0] ?? [])),
            array_map(fn (array $entry): array => array_map(
                fn (string $field, mixed $value): string => $this->readable($field, $value),
                array_keys($entry),
                $entry,
            ), $data),
        )];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, DsarReportTable>
     */
    private function entryTables(string $section, array $entry, ?string $caption): array
    {
        $fields = array_filter($entry, fn (mixed $value): bool => ! is_array($value));
        $tables = $fields === [] ? [] : [new DsarReportTable($caption, [], array_map(
            fn (string $field, mixed $value): array => [$this->label($section, $field), $this->readable($field, $value)],
            array_keys($fields),
            $fields,
        ))];

        foreach (array_filter($entry, is_array(...)) as $field => $list) {
            $tables = array_merge($tables, $this->tablesOf($section, $list, $this->label($section, $field)));
        }

        return $tables;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isFlat(array $entry): bool
    {
        return array_filter($entry, is_array(...)) === [];
    }

    private function label(string $section, string $field): string
    {
        return __("dsar.fields.{$section}.{$field}");
    }

    /**
     * A value as a person reads it: a time in words, Yes / No,
     * a dash for nothing, a code as its meaning with the code after it.
     */
    private function readable(string $field, mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => __($value ? 'dsar.report.yes' : 'dsar.report.no'),
            $field === 'at' || str_ends_with($field, '_at') => $this->readableTime(CarbonImmutable::parse((string) $value)),
            default => $this->explained($field, (string) $value),
        };
    }

    private function explained(string $field, string $code): string
    {
        $meaning = $this->meaningOf($field, $code);

        return $meaning === null ? $code : "{$meaning} ({$code})";
    }

    private function meaningOf(string $field, string $code): ?string
    {
        if (Lang::has("dsar.codes.{$field}.{$code}")) {
            return __("dsar.codes.{$field}.{$code}");
        }

        return $field === 'role' ? $this->roleNames()[$code] ?? null : null;
    }

    /**
     * @return array<string, string>
     */
    private function roleNames(): array
    {
        return $this->roleNames ??= Role::query()->pluck('name', 'code')->all();
    }

    private function readableTime(CarbonInterface $time): string
    {
        return $time->utc()->format('j M Y, H:i').' UTC';
    }
}
