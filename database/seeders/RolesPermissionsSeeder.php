<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use App\Foundation\RolesPermissions\Exceptions\SeederSourceException;

class RolesPermissionsSeeder extends Seeder
{
    public function __construct(
        private readonly string $rolesYamlPath = '',
        private readonly string $permissionsYamlPath = '',
        private readonly string $rolePermissionsYamlPath = '',
    ) {}

    public function run(): void
    {
        $this->loadYaml($this->rolesYamlPath);
        $this->loadYaml($this->permissionsYamlPath);
    }

    /**
     * @return array<mixed>
     */
    private function loadYaml(string $path): array
    {
        if (! is_file($path)) {
            throw SeederSourceException::missingFile($path);
        }

        try {
            return Yaml::parseFile($path) ?? [];
        } catch (ParseException $e) {
            throw SeederSourceException::parseFailure($path, $e);
        }
    }
}
