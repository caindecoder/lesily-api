<?php

namespace Database\Seeders;

use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use Symfony\Component\Yaml\Yaml;
use App\Foundation\CountryConfig\Entities\Country;
use Symfony\Component\Yaml\Exception\ParseException;
use App\Foundation\CountryConfig\Exceptions\CountrySourceException;

class CountriesSeeder extends Seeder
{
    protected const array DEFAULT_COLUMNS = [
        'name',
        'timezone',
        'currency',
        'locale_default',
        'fiscal_year_start_month',
        'working_days_per_week',
        'default_work_hours_per_day',
    ];

    public function __construct(
        private readonly string $countriesYamlPath = '',
    ) {}

    /**
     * Build the seeder against the bundled countries file — the deploy and test
     * path (the constructor path is not container-resolvable).
     */
    public static function fromDefaultPath(): self
    {
        return new self(countriesYamlPath: database_path('seeders/data/countries.yaml'));
    }

    public function run(): void
    {
        $rows = array_map(
            fn (array $row): array => ['id' => Str::uuid7()->toString(), ...$row],
            $this->loadYaml($this->countriesYamlPath),
        );

        Country::query()->upsert($rows, ['code'], self::DEFAULT_COLUMNS);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadYaml(string $path): array
    {
        if (! is_file($path)) {
            throw CountrySourceException::missingFile($path);
        }

        try {
            return Yaml::parseFile($path) ?? [];
        } catch (ParseException $e) {
            throw CountrySourceException::parseFailure($path, $e);
        }
    }
}
